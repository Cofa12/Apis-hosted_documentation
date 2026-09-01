<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\ExampleFactory;
use Illuminate\Support\Str;
use ReflectionNamedType;
use Throwable;

/**
 * Documents the `{placeholders}` in the route URI, using the route's own
 * constraints and the action's type hints to work out what they hold.
 */
class UrlParameterExtractor implements Extractor
{
    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $route = $context->route;
        $wheres = $route->wheres ?? [];
        $parameters = [];

        preg_match_all('/\{([a-zA-Z0-9_]+)(\??)(?::([a-zA-Z0-9_]+))?\}/', $route->uri(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = $match[1];
            $optional = $match[2] === '?';
            $bindingField = $match[3] ?? $this->bindingField($route, $name);

            $model = $this->modelFor($context, $name);
            $type = $this->resolveType($name, $bindingField, $wheres[$name] ?? null, $model);

            $parameter = new Parameter(
                name: $name,
                type: $type,
                required: ! $optional,
                description: $this->describe($name, $bindingField, $model, $wheres[$name] ?? null),
                example: ExampleFactory::forParameter(
                    $bindingField !== null ? $name . '_' . $bindingField : $name,
                    $type
                ),
            );

            if ($model !== null) {
                $parameter->rules[] = 'exists:' . Str::snake(Str::pluralStudly(class_basename($model)));
            }

            $parameters[] = $parameter;
        }

        if ($parameters !== []) {
            $endpoint->mergeParameters('urlParameters', $parameters);
        }
    }

    protected function bindingField(mixed $route, string $name): ?string
    {
        try {
            return method_exists($route, 'bindingFieldFor') ? $route->bindingFieldFor($name) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The model class bound to a route parameter, if the action type hints one. */
    protected function modelFor(RouteContext $context, string $name): ?string
    {
        $reflection = $context->methodReflection;

        if ($reflection === null) {
            return null;
        }

        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getName() !== $name) {
                continue;
            }

            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                return null;
            }

            $class = $type->getName();

            return class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)
                ? $class
                : null;
        }

        return null;
    }

    protected function resolveType(string $name, ?string $bindingField, ?string $where, ?string $model): string
    {
        if ($where !== null) {
            if (preg_match('/^\[?0-9|^\\\\d|^\[0-9\]/', $where) === 1 && ! str_contains($where, 'a-z')) {
                return 'integer';
            }

            return 'string';
        }

        $field = $bindingField ?? $name;

        if (Str::contains(Str::lower($field), ['uuid', 'ulid', 'slug', 'token', 'code', 'email'])) {
            return 'string';
        }

        if ($field === 'id' || Str::endsWith($field, '_id') || $model !== null) {
            return 'integer';
        }

        return 'string';
    }

    protected function describe(string $name, ?string $bindingField, ?string $model, ?string $where): string
    {
        $subject = Str::lower(Str::headline($name));
        $field = $bindingField ?? 'id';

        if ($model !== null) {
            $sentence = 'The ' . $field . ' of the ' . $subject . ' to operate on.';
        } elseif ($bindingField !== null) {
            $sentence = 'The ' . $bindingField . ' of the ' . $subject . '.';
        } else {
            $sentence = 'The ' . $subject . ' identifier.';
        }

        if ($where !== null) {
            $sentence .= ' Must match `' . $where . '`.';
        }

        return $sentence;
    }
}
