<?php

namespace Cofa\ApiDocs\Support;

use Cofa\ApiDocs\Data\Parameter;

/**
 * Laravel validation rules are flat and dotted ("address.city", "items.*.sku").
 * OpenAPI schemas are nested, so this turns one into the other.
 */
class ParameterTree
{
    /**
     * @param  array<int, Parameter>  $parameters
     * @return array<int, Parameter>
     */
    public static function nest(array $parameters): array
    {
        /** @var array<string, Parameter> $index dotted path => parameter */
        $index = [];
        $roots = [];

        // Shorter paths first so parents always exist before their children.
        // Sorting on depth alone keeps the declaration order of siblings,
        // which is the order the developer wrote the rules in.
        usort($parameters, fn (Parameter $a, Parameter $b) => substr_count($a->name, '.')
            <=> substr_count($b->name, '.'));

        foreach ($parameters as $parameter) {
            $segments = explode('.', $parameter->name);

            if (count($segments) === 1) {
                if (isset($index[$parameter->name])) {
                    $index[$parameter->name]->mergeFrom($parameter);

                    continue;
                }

                $index[$parameter->name] = $parameter;
                $roots[] = $parameter;

                continue;
            }

            $leaf = array_pop($segments);
            $parent = self::ensureParent($segments, $index, $roots);

            if ($parent === null) {
                $roots[] = $parameter;
                $index[$parameter->name] = $parameter;

                continue;
            }

            // "tags.*" describes the items of "tags" rather than a child field.
            if ($leaf === '*') {
                $parent->type = self::arrayTypeFor($parameter->type);
                $parent->rules = array_values(array_unique(array_merge($parent->rules, $parameter->rules)));

                if ($parent->description === '') {
                    $parent->description = $parameter->description;
                }

                if ($parent->example === null && $parameter->example !== null) {
                    $parent->example = [$parameter->example, $parameter->example];
                }

                if ($parameter->enum !== []) {
                    $parent->enum = $parameter->enum;
                }

                continue;
            }

            $parameter->name = $leaf;

            // "address" validated as `array` but with `address.city` children
            // is an object; "contacts.*.name" already promoted its parent.
            if ($parent->type === 'array') {
                $parent->type = 'object';
            }

            foreach ($parent->children as $existing) {
                if ($existing->name === $leaf) {
                    $existing->mergeFrom($parameter);

                    continue 2;
                }
            }

            $parent->children[] = $parameter;
            $index[implode('.', $segments) . '.' . $leaf] = $parameter;
        }

        return $roots;
    }

    /**
     * Walk (creating as needed) the chain of parents for a dotted path.
     *
     * @param  array<int, string>  $segments
     * @param  array<string, Parameter>  $index
     * @param  array<int, Parameter>  $roots
     */
    protected static function ensureParent(array $segments, array &$index, array &$roots): ?Parameter
    {
        $parent = null;
        $path = '';

        foreach ($segments as $segment) {
            $path = $path === '' ? $segment : $path . '.' . $segment;

            if ($segment === '*') {
                // The wildcard is absorbed by its parent: it turns it into a list.
                if ($parent !== null) {
                    $parent->type = 'object[]';
                }

                continue;
            }

            if (isset($index[$path])) {
                $parent = $index[$path];

                continue;
            }

            $created = new Parameter(name: $segment, type: 'object');
            $index[$path] = $created;

            if ($parent === null) {
                $roots[] = $created;
            } else {
                $parent->children[] = $created;
            }

            $parent = $created;
        }

        return $parent;
    }

    protected static function arrayTypeFor(string $itemType): string
    {
        return match ($itemType) {
            'object' => 'object[]',
            'integer' => 'integer[]',
            'number' => 'number[]',
            'boolean' => 'boolean[]',
            default => 'string[]',
        };
    }

    /**
     * Flatten a nested tree back into dotted parameters (used by exporters).
     *
     * @param  array<int, Parameter>  $parameters
     * @return array<int, Parameter>
     */
    public static function flatten(array $parameters, string $prefix = ''): array
    {
        $flat = [];

        foreach ($parameters as $parameter) {
            $name = $prefix === '' ? $parameter->name : $prefix . '.' . $parameter->name;
            $clone = clone $parameter;
            $clone->name = $name;
            $clone->children = [];
            $flat[] = $clone;

            if ($parameter->children !== []) {
                $childPrefix = str_ends_with($parameter->type, '[]') ? $name . '.*' : $name;
                $flat = array_merge($flat, self::flatten($parameter->children, $childPrefix));
            }
        }

        return $flat;
    }
}
