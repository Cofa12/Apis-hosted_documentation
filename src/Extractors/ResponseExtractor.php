<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\ResponseExample;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\AstResolver;
use Cofa\ApiDocs\Support\ModelSchemaInspector;
use Cofa\ApiDocs\Support\ResourceSchemaInspector;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use ReflectionNamedType;

/**
 * Works out what an endpoint actually returns by following the action's return
 * statements into API resources, models and `response()->json()` calls.
 */
class ResponseExtractor implements Extractor
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected AstResolver $ast,
        protected ResourceSchemaInspector $resources,
        protected ModelSchemaInspector $models,
        protected array $config = [],
    ) {
    }

    protected ?string $lastSchemaName = null;

    protected bool $lastCollection = false;

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $this->lastSchemaName = null;
        $this->lastCollection = false;

        $this->fromResourceTags($endpoint);
        $this->fromReturnType($endpoint, $context);
        $this->fromActionBody($endpoint, $context);
        $this->ensureSuccessResponse($endpoint);

        $endpoint->sortResponses();
    }

    /** Responses declared through @apiResource / #[ApiResponse(resource: ...)]. */
    protected function fromResourceTags(Endpoint $endpoint): void
    {
        foreach ($endpoint->meta['resource_responses'] ?? [] as $declaration) {
            $class = $declaration['class'] ?? null;

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $shape = $this->resources->isResource($class)
                ? $this->resources->shapeFor($class)
                : $this->models->shapeFor($class);

            if ($shape === null) {
                continue;
            }

            $collection = (bool) ($declaration['collection'] ?? false);
            $content = $collection
                ? $this->wrapCollection($endpoint, [$shape, $shape])
                : $this->wrap($shape);

            $endpoint->addResponse(new ResponseExample(
                status: (int) ($declaration['status'] ?? 200),
                content: $content,
                description: (string) ($declaration['description'] ?? ''),
                schemaName: class_basename($class),
                collection: $collection,
            ));
        }
    }

    /** A declared return type is the cheapest and most reliable signal. */
    protected function fromReturnType(Endpoint $endpoint, RouteContext $context): void
    {
        $type = $context->methodReflection?->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $class = $type->getName();

        if ($this->resources->isResource($class)) {
            $shape = $this->resources->shapeFor($class);

            if ($shape !== null) {
                $collection = is_subclass_of($class, \Illuminate\Http\Resources\Json\ResourceCollection::class);

                $endpoint->addResponse(new ResponseExample(
                    status: $this->defaultStatus($endpoint),
                    content: $collection
                        ? $this->wrapCollection($endpoint, [$shape, $shape])
                        : $this->wrap($shape),
                    schemaName: class_basename($class),
                    collection: $collection,
                ), overwrite: false);
            }

            return;
        }

        if ($this->models->isModel($class)) {
            $shape = $this->models->shapeFor($class);

            if ($shape !== null) {
                $endpoint->addResponse(new ResponseExample(
                    status: $this->defaultStatus($endpoint),
                    content: $shape,
                    schemaName: class_basename($class),
                ), overwrite: false);
            }
        }
    }

    protected function fromActionBody(Endpoint $endpoint, RouteContext $context): void
    {
        if ($context->controller === null || $context->action === null) {
            return;
        }

        $node = $this->ast->findMethod($context->controller, $context->action);

        if ($node === null) {
            return;
        }

        /** @var array<int, Node\Stmt\Return_> $returns */
        $returns = $this->ast->findInstanceOf([$node], Node\Stmt\Return_::class);

        foreach ($returns as $return) {
            if ($return->expr === null) {
                continue;
            }

            $this->lastSchemaName = null;
            $this->lastCollection = false;
            $resolved = $this->fromExpression($endpoint, $return->expr);

            if ($resolved === null) {
                continue;
            }

            [$status, $content, $description] = $resolved;

            $endpoint->addResponse(
                new ResponseExample(
                    status: $status,
                    content: $content,
                    description: $description,
                    schemaName: $this->lastSchemaName,
                    collection: $this->lastCollection,
                ),
                overwrite: false
            );
        }

        // abort(404) / abort_if(...) document the failure paths precisely.
        foreach ($this->ast->findInstanceOf([$node], Expr\FuncCall::class) as $call) {
            /** @var Expr\FuncCall $call */
            $name = $call->name instanceof Node\Name ? $call->name->toString() : '';

            if (! in_array($name, ['abort', 'abort_if', 'abort_unless'], true)) {
                continue;
            }

            $index = $name === 'abort' ? 0 : 1;
            $arg = $call->args[$index] ?? null;

            if (! $arg instanceof Arg) {
                continue;
            }

            $status = $this->ast->resolveValue($arg->value);

            if (! is_numeric($status)) {
                continue;
            }

            $message = null;
            $messageArg = $call->args[$index + 1] ?? null;

            if ($messageArg instanceof Arg) {
                $resolved = $this->ast->resolveValue($messageArg->value);
                $message = is_string($resolved) ? $resolved : null;
            }

            $endpoint->addResponse(new ResponseExample(
                status: (int) $status,
                content: ['message' => $message ?? (ResponseExample::TEXTS[(int) $status] ?? 'Error')],
            ), overwrite: false);
        }
    }

    /**
     * @return array{0: int, 1: mixed, 2: string}|null
     */
    protected function fromExpression(Endpoint $endpoint, Expr $expr, ?int $status = null): ?array
    {
        // response()->json([...], 201) and friends – walk the fluent chain.
        if ($expr instanceof Expr\MethodCall) {
            return $this->fromResponseChain($endpoint, $expr, $status);
        }

        if ($expr instanceof Expr\StaticCall) {
            $class = $this->ast->classNameOf($expr);
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

            if ($class !== null && $this->resources->isResource($class)) {
                $shape = $this->resources->shapeFor($class);

                if ($shape === null) {
                    return null;
                }

                $this->lastSchemaName = class_basename($class);
                $this->lastCollection = $method === 'collection';

                $content = $this->lastCollection
                    ? $this->wrapCollection($endpoint, [$shape, $shape])
                    : $this->wrap($shape);

                return [$status ?? $this->defaultStatus($endpoint), $content, ''];
            }

            if ($class !== null && Str::endsWith($class, 'Response') && $method === 'json') {
                return [$status ?? $this->defaultStatus($endpoint), null, ''];
            }

            return null;
        }

        if ($expr instanceof Expr\New_) {
            $class = $this->ast->classNameOf($expr);

            if ($class !== null && $this->resources->isResource($class)) {
                $shape = $this->resources->shapeFor($class);

                if ($shape === null) {
                    return null;
                }

                $this->lastSchemaName = class_basename($class);

                return [$status ?? $this->defaultStatus($endpoint), $this->wrap($shape), ''];
            }

            if ($class !== null && Str::contains($class, ['JsonResponse', 'Response'])) {
                $first = $expr->args[0] ?? null;

                if ($first instanceof Arg && $first->value instanceof Expr\Array_) {
                    return [
                        $status ?? $this->defaultStatus($endpoint),
                        $this->resources->shapeFromArrayNode($first->value),
                        '',
                    ];
                }
            }

            return null;
        }

        if ($expr instanceof Expr\Array_) {
            return [
                $status ?? $this->defaultStatus($endpoint),
                $this->resources->shapeFromArrayNode($expr),
                '',
            ];
        }

        return null;
    }

    /**
     * @return array{0: int, 1: mixed, 2: string}|null
     */
    protected function fromResponseChain(Endpoint $endpoint, Expr\MethodCall $call, ?int $status): ?array
    {
        $chain = [];
        $cursor = $call;

        while ($cursor instanceof Expr\MethodCall) {
            $chain[] = $cursor;
            $cursor = $cursor->var;
        }

        $chain = array_reverse($chain);
        $content = null;
        $found = false;

        foreach ($chain as $link) {
            $method = $link->name instanceof Node\Identifier ? $link->name->toString() : '';

            switch ($method) {
                case 'json':
                    $found = true;
                    $first = $link->args[0] ?? null;

                    if ($first instanceof Arg && $first->value instanceof Expr\Array_) {
                        $content = $this->resources->shapeFromArrayNode($first->value);
                    } elseif ($first instanceof Arg) {
                        $content ??= $this->resources->valueFromNode($first->value, 'data');
                    }

                    $second = $link->args[1] ?? null;

                    if ($second instanceof Arg) {
                        $resolved = $this->ast->resolveValue($second->value);
                        $status = is_numeric($resolved) ? (int) $resolved : $status;
                    }

                    break;

                case 'noContent':
                    $found = true;
                    $status = 204;
                    $content = null;

                    break;

                case 'setStatusCode':
                case 'status':
                    $first = $link->args[0] ?? null;

                    if ($first instanceof Arg) {
                        $resolved = $this->ast->resolveValue($first->value);

                        if (is_numeric($resolved)) {
                            $found = true;
                            $status = (int) $resolved;
                        }
                    }

                    break;

                case 'response':
                    $found = true;

                    break;

                case 'additional':
                    $first = $link->args[0] ?? null;

                    if ($first instanceof Arg && $first->value instanceof Expr\Array_ && is_array($content)) {
                        $content = array_merge($content, $this->resources->shapeFromArrayNode($first->value));
                    }

                    break;
            }
        }

        // `(new UserResource($user))->response()->setStatusCode(201)`
        $root = $chain[0]->var ?? null;

        if ($root instanceof Expr) {
            $resolved = $this->fromExpression($endpoint, $root, $status);

            if ($resolved !== null) {
                return [$status ?? $resolved[0], $content ?? $resolved[1], $resolved[2]];
            }
        }

        return $found ? [$status ?? $this->defaultStatus($endpoint), $content, ''] : null;
    }

    protected function ensureSuccessResponse(Endpoint $endpoint): void
    {
        if ($endpoint->successResponses() !== []) {
            return;
        }

        $status = $this->defaultStatus($endpoint);

        $endpoint->addResponse(new ResponseExample(
            status: $status,
            content: $status === 204 ? null : null,
            description: $status === 204 ? 'The resource was deleted.' : '',
        ), overwrite: false);
    }

    protected function defaultStatus(Endpoint $endpoint): int
    {
        $defaults = (array) data_get($this->config, 'responses.default_status', []);

        return (int) ($defaults[$endpoint->method()] ?? 200);
    }

    /** @param array<string, mixed> $shape */
    protected function wrap(array $shape): array
    {
        $wrapper = data_get($this->config, 'responses.resource_wrapper', 'data');

        return is_string($wrapper) && $wrapper !== '' ? [$wrapper => $shape] : $shape;
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function wrapCollection(Endpoint $endpoint, array $items): array
    {
        $wrapper = data_get($this->config, 'responses.resource_wrapper', 'data');
        $payload = is_string($wrapper) && $wrapper !== '' ? [$wrapper => $items] : $items;

        $paginated = $endpoint->meta['paginated'] ?? null;

        if ($paginated === null || ! is_array($payload)) {
            return $payload;
        }

        if ($paginated === 'cursorPaginate') {
            return $payload + [
                'links' => [
                    'first' => null,
                    'last' => null,
                    'prev' => null,
                    'next' => 'https://example.com/api/resource?cursor=eyJpZCI6MTV9',
                ],
                'meta' => ['path' => 'https://example.com/api/resource', 'per_page' => 15, 'next_cursor' => 'eyJpZCI6MTV9', 'prev_cursor' => null],
            ];
        }

        return $payload + [
            'links' => [
                'first' => 'https://example.com/api/resource?page=1',
                'last' => 'https://example.com/api/resource?page=4',
                'prev' => null,
                'next' => 'https://example.com/api/resource?page=2',
            ],
            'meta' => [
                'current_page' => 1,
                'from' => 1,
                'last_page' => 4,
                'path' => 'https://example.com/api/resource',
                'per_page' => 15,
                'to' => 15,
                'total' => 60,
            ],
        ];
    }
}
