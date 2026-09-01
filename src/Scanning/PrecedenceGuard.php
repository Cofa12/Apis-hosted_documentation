<?php

namespace Cofa\ApiDocs\Scanning;

use Cofa\ApiDocs\Data\Endpoint;

/**
 * Applies the documented precedence rule: where a docblock tag and a PHP
 * attribute describe the same thing, the attribute wins — field by field, so
 * a docblock is only overruled on the fields the attribute actually declares.
 *
 * Every overruled value is reported rather than silently dropped.
 */
class PrecedenceGuard
{
    /** Bucket => how it reads in a warning, and the attribute that owns it. */
    protected const BUCKETS = [
        'bodyParameters' => ['body param', 'ApiParam'],
        'queryParameters' => ['query param', 'ApiParam'],
        'urlParameters' => ['url param', 'ApiParam'],
        'headers' => ['header', 'ApiHeader'],
        'responses' => ['response', 'ApiResponse'],
    ];

    /** Operation level field => the attribute that declares it. */
    protected const OPERATION = [
        'summary' => 'ApiDoc',
        'description' => 'ApiDoc',
        'operationId' => 'ApiDoc',
        'deprecated' => 'ApiDoc',
        'group' => 'ApiGroup',
        'authenticated' => 'Authenticated',
    ];

    /**
     * @param  array<string, mixed>  $docblock  what the docblock declared
     * @param  array<string, mixed>  $attribute  what the attributes declared
     * @return array<int, Conflict>
     */
    public function compare(Endpoint $endpoint, array $docblock, array $attribute): array
    {
        $handler = $this->handler($endpoint);
        $conflicts = [];

        foreach (self::OPERATION as $field => $attributeName) {
            if (! array_key_exists($field, $docblock['operation'] ?? [])
                || ! array_key_exists($field, $attribute['operation'] ?? [])) {
                continue;
            }

            $before = $docblock['operation'][$field];
            $after = $attribute['operation'][$field];

            if ($this->same($before, $after)) {
                continue;
            }

            $conflicts[] = new Conflict($handler, $field, null, $field, $before, $after, $attributeName);
        }

        foreach (self::BUCKETS as $bucket => [$location, $attributeName]) {
            $before = $docblock[$bucket] ?? [];
            $after = $attribute[$bucket] ?? [];

            foreach ($after as $name => $properties) {
                if (! isset($before[$name]) || ! is_array($properties) || ! is_array($before[$name])) {
                    continue;
                }

                foreach ($properties as $property => $value) {
                    if (! array_key_exists($property, $before[$name]) || $this->same($before[$name][$property], $value)) {
                        continue;
                    }

                    $conflicts[] = new Conflict(
                        $handler,
                        $location,
                        (string) $name,
                        (string) $property,
                        $before[$name][$property],
                        $value,
                        $attributeName,
                    );
                }
            }
        }

        return $conflicts;
    }

    protected function same(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return trim($a) === trim($b);
        }

        return $a === $b;
    }

    protected function handler(Endpoint $endpoint): string
    {
        if ($endpoint->controller === null) {
            return $endpoint->method() . ' /' . ltrim($endpoint->uri, '/');
        }

        $class = class_basename($endpoint->controller);

        return $endpoint->action === null ? $class : $class . '::' . $endpoint->action;
    }
}
