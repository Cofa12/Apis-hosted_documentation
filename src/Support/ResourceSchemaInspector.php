<?php

namespace Cofa\ApiDocs\Support;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use ReflectionClass;
use Throwable;

/**
 * Reads an API resource's `toArray()` and rebuilds the payload it produces so
 * the documentation can show a realistic response body – including nested and
 * collection resources.
 */
class ResourceSchemaInspector
{
    /** @var array<string, array<string, mixed>|null> */
    protected array $cache = [];

    public function __construct(
        protected AstResolver $ast,
        protected ModelSchemaInspector $models,
        protected int $maxDepth = 4,
    ) {
    }

    public function isResource(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        return $reflection->isSubclassOf(\Illuminate\Http\Resources\Json\JsonResource::class)
            || $reflection->isSubclassOf(\Illuminate\Http\Resources\Json\ResourceCollection::class);
    }

    /**
     * @param  array<int, string>  $visited
     * @return array<string, mixed>|null
     */
    public function shapeFor(string $class, int $depth = 0, array $visited = []): ?array
    {
        $key = $class . '@' . $depth;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($depth > $this->maxDepth || in_array($class, $visited, true)) {
            return $this->cache[$key] = null;
        }

        $visited[] = $class;
        $method = $this->ast->findMethod($class, 'toArray');

        if ($method === null) {
            // A resource without toArray() falls back to the model it wraps.
            return $this->cache[$key] = $this->models->shapeForResource($class);
        }

        /** @var array<int, Node\Stmt\Return_> $returns */
        $returns = $this->ast->findInstanceOf([$method], Node\Stmt\Return_::class);

        foreach ($returns as $return) {
            if (! $return->expr instanceof Expr\Array_) {
                continue;
            }

            return $this->cache[$key] = $this->shapeFromArray($return->expr, $depth, $visited, $class);
        }

        return $this->cache[$key] = null;
    }

    /**
     * Resolve any array literal the same way a resource body is resolved:
     * nested resources are followed and unknown values become examples.
     *
     * @return array<string, mixed>
     */
    public function shapeFromArrayNode(Expr\Array_ $array, int $depth = 0): array
    {
        return $this->shapeFromArray($array, $depth, [], '');
    }

    /** Resolve a single expression into an example value. */
    public function valueFromNode(Expr $expr, string $key = 'value', int $depth = 0): mixed
    {
        return $this->valueFor($expr, $key, $depth, [], '');
    }

    /**
     * @param  array<int, string>  $visited
     * @return array<string, mixed>
     */
    protected function shapeFromArray(Expr\Array_ $array, int $depth, array $visited, string $owner): array
    {
        $shape = [];

        foreach ($array->items as $item) {
            if ($item === null || $item->unpack) {
                if ($item !== null && $item->unpack) {
                    // `...$this->extra()` – nothing we can resolve, keep going.
                    continue;
                }

                continue;
            }

            $key = $item->key === null ? null : $this->ast->resolveValue($item->key);

            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $shape[(string) $key] = $this->valueFor($item->value, (string) $key, $depth, $visited, $owner);
        }

        return $shape;
    }

    /** @param array<int, string> $visited */
    protected function valueFor(Expr $expr, string $key, int $depth, array $visited, string $owner): mixed
    {
        if ($expr instanceof Expr\Array_) {
            $nested = $this->shapeFromArray($expr, $depth + 1, $visited, $owner);

            return $nested === [] ? [] : $nested;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        // Covers Int_/Float_ (php-parser 5) as well as LNumber/DNumber (4.x).
        if ($expr instanceof Node\Scalar && property_exists($expr, 'value') && is_scalar($expr->value)) {
            return $expr->value;
        }

        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());

            if (in_array($name, ['true', 'false'], true)) {
                return $name === 'true';
            }

            if ($name === 'null') {
                return null;
            }
        }

        // new UserResource($this->author)
        if ($expr instanceof Expr\New_) {
            $class = $this->ast->classNameOf($expr);

            if ($class !== null && $this->isResource($class)) {
                return $this->shapeFor($class, $depth + 1, $visited) ?? ExampleFactory::forName($key, 'object');
            }
        }

        // UserResource::collection(...) / UserResource::make(...)
        if ($expr instanceof Expr\StaticCall) {
            $class = $this->ast->classNameOf($expr);
            $method = $expr->name instanceof Node\Identifier ? $expr->name->toString() : '';

            if ($class !== null && $this->isResource($class)) {
                $shape = $this->shapeFor($class, $depth + 1, $visited);

                if ($shape !== null) {
                    return $method === 'collection' ? [$shape] : $shape;
                }
            }
        }

        // $this->whenLoaded('posts', fn () => ...), $this->when($x, $value), ->toIso8601String()
        if ($expr instanceof Expr\MethodCall) {
            return $this->fromMethodCall($expr, $key, $depth, $visited, $owner);
        }

        if ($expr instanceof Expr\NullsafePropertyFetch || $expr instanceof Expr\PropertyFetch) {
            $name = $expr->name instanceof Node\Identifier ? $expr->name->toString() : $key;

            return ExampleFactory::forName($name !== '' ? $name : $key);
        }

        if ($expr instanceof Expr\Ternary) {
            $branch = $expr->if ?? $expr->cond;

            return $this->valueFor($branch, $key, $depth, $visited, $owner);
        }

        return ExampleFactory::forName($key);
    }

    /** @param array<int, string> $visited */
    protected function fromMethodCall(Expr\MethodCall $call, string $key, int $depth, array $visited, string $owner): mixed
    {
        $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';

        $conditional = [
            'when' => 1,
            'whenLoaded' => 1,
            'whenNotNull' => 0,
            'whenAppended' => 1,
            'whenCounted' => 1,
            'whenPivotLoaded' => 1,
            'mergeWhen' => 1,
        ];

        if (array_key_exists($name, $conditional)) {
            $index = $conditional[$name];
            $arg = $call->args[$index] ?? null;

            if ($arg instanceof Arg) {
                $value = $arg->value;

                // Closures wrap the real payload: fn () => new PostResource(...)
                if ($value instanceof Expr\ArrowFunction) {
                    return $this->valueFor($value->expr, $key, $depth, $visited, $owner);
                }

                if ($value instanceof Expr\Closure) {
                    foreach ($this->ast->findInstanceOf([$value], Node\Stmt\Return_::class) as $return) {
                        if ($return->expr !== null) {
                            return $this->valueFor($return->expr, $key, $depth, $visited, $owner);
                        }
                    }
                }

                return $this->valueFor($value, $key, $depth, $visited, $owner);
            }

            if ($name === 'whenLoaded' || $name === 'whenCounted') {
                $relation = isset($call->args[0]) && $call->args[0] instanceof Arg
                    ? $this->ast->resolveValue($call->args[0]->value)
                    : $key;

                return $name === 'whenCounted' ? 3 : ExampleFactory::forName(is_string($relation) ? $relation : $key);
            }
        }

        // Date formatters keep their key based example but as an ISO string.
        if (Str::startsWith($name, ['toIso', 'toDate', 'format', 'toJSON', 'toAtom', 'diffForHumans'])) {
            return $name === 'diffForHumans' ? '2 hours ago' : '2026-01-15T09:30:00.000000Z';
        }

        if ($name === 'count') {
            return 3;
        }

        if ($name === 'toArray' || $name === 'all') {
            $root = $call->var;

            if ($root instanceof Expr\StaticCall || $root instanceof Expr\New_) {
                return $this->valueFor($root, $key, $depth, $visited, $owner);
            }
        }

        return ExampleFactory::forName($key);
    }
}
