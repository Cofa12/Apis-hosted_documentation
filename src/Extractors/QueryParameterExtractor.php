<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\AstResolver;
use Cofa\ApiDocs\Support\ExampleFactory;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;

/**
 * Query string values are rarely validated, so they are read straight from the
 * action body: `$request->query('search')`, `$request->boolean('active')`,
 * `$request->input('sort', 'created_at')` and friends. Pagination is detected
 * from `->paginate()` calls.
 */
class QueryParameterExtractor implements Extractor
{
    /** Reader method => the type it produces. */
    protected const READERS = [
        'query' => 'string',
        'input' => 'string',
        'get' => 'string',
        'string' => 'string',
        'integer' => 'integer',
        'float' => 'number',
        'boolean' => 'boolean',
        'date' => 'date',
        'enum' => 'string',
        'has' => 'string',
        'filled' => 'string',
        'whenHas' => 'string',
        'whenFilled' => 'string',
    ];

    public function __construct(protected AstResolver $ast)
    {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        if ($context->controller === null || $context->action === null) {
            return;
        }

        $node = $this->ast->findMethod($context->controller, $context->action);

        if ($node === null) {
            return;
        }

        $parameters = [];

        foreach ($this->ast->findInstanceOf([$node], Expr\MethodCall::class) as $call) {
            /** @var Expr\MethodCall $call */
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

            if ($this->isPaginator($name)) {
                $parameters = array_merge($parameters, $this->paginationParameters($endpoint, $call));

                continue;
            }

            if (! isset(self::READERS[$name]) || ! $this->readsFromRequest($call)) {
                continue;
            }

            $first = $call->args[0] ?? null;

            if (! $first instanceof Arg) {
                continue;
            }

            $field = $this->ast->resolveValue($first->value);

            if (! is_string($field) || $field === '' || str_contains($field, '(') || str_contains($field, '$')) {
                continue;
            }

            $type = self::READERS[$name];
            $default = null;

            if (isset($call->args[1]) && $call->args[1] instanceof Arg && $name !== 'enum') {
                $resolved = $this->ast->resolveValue($call->args[1]->value);
                $default = is_scalar($resolved) ? $resolved : null;
            }

            $parameters[] = new Parameter(
                name: $field,
                type: $type,
                required: false,
                description: $default === null ? '' : 'Defaults to `' . var_export($default, true) . '`.',
                example: $default ?? ExampleFactory::forParameter($field, $type),
                default: $default,
            );
        }

        if ($parameters !== []) {
            $endpoint->mergeParameters('queryParameters', $this->unique($parameters));
        }
    }

    protected function isPaginator(string $method): bool
    {
        return in_array($method, ['paginate', 'simplePaginate', 'cursorPaginate'], true);
    }

    /** @return array<int, Parameter> */
    protected function paginationParameters(Endpoint $endpoint, Expr\MethodCall $call): array
    {
        $name = $call->name instanceof Node\Identifier ? $call->name->toString() : 'paginate';
        $endpoint->meta['paginated'] = $name;

        if ($name === 'cursorPaginate') {
            return [
                new Parameter('cursor', 'string', false, 'The cursor returned by the previous page.', 'eyJpZCI6MTV9'),
                new Parameter('per_page', 'integer', false, 'Number of items per page.', 15),
            ];
        }

        $perPage = 15;

        if (isset($call->args[0]) && $call->args[0] instanceof Arg) {
            $resolved = $this->ast->resolveValue($call->args[0]->value);
            $perPage = is_numeric($resolved) ? (int) $resolved : 15;
        }

        return [
            new Parameter('page', 'integer', false, 'The page to return.', 1),
            new Parameter('per_page', 'integer', false, 'Number of items per page.', $perPage),
        ];
    }

    /** Only count reads that happen on something request shaped. */
    protected function readsFromRequest(Expr\MethodCall $call): bool
    {
        $var = $call->var;

        if ($var instanceof Expr\Variable && is_string($var->name)) {
            return Str::contains(Str::lower($var->name), 'request');
        }

        if ($var instanceof Expr\FuncCall && $var->name instanceof Node\Name) {
            return $var->name->toString() === 'request';
        }

        if ($var instanceof Expr\StaticCall || $var instanceof Expr\MethodCall) {
            $class = $this->ast->classNameOf($var) ?? '';

            return Str::contains($class, 'Request');
        }

        return false;
    }

    /**
     * @param  array<int, Parameter>  $parameters
     * @return array<int, Parameter>
     */
    protected function unique(array $parameters): array
    {
        $seen = [];

        foreach ($parameters as $parameter) {
            if (isset($seen[$parameter->name])) {
                $seen[$parameter->name]->mergeFrom($parameter);

                continue;
            }

            $seen[$parameter->name] = $parameter;
        }

        return array_values($seen);
    }
}
