<?php

namespace Cofa\ApiDocs\OpenApi;

use Cofa\ApiDocs\Data\Parameter;
use Illuminate\Support\Str;

/**
 * Builds JSON Schema (draft 2020-12, as used by OpenAPI 3.1) out of the
 * extracted parameters and example payloads.
 */
class SchemaFactory
{
    public function __construct(protected bool $nullableAsType = true)
    {
    }

    /**
     * An object schema describing a whole set of parameters.
     *
     * @param  array<int, Parameter>  $parameters
     * @return array<string, mixed>
     */
    public function objectFromParameters(array $parameters): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            $properties[$parameter->name] = $this->fromParameter($parameter);

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    public function fromParameter(Parameter $parameter): array
    {
        $schema = $this->baseType($parameter);

        if ($parameter->description !== '') {
            $schema['description'] = $parameter->description;
        }

        if ($parameter->enum !== []) {
            $schema['enum'] = $this->castEnum($parameter);
        }

        if ($parameter->default !== null) {
            $schema['default'] = $parameter->default;
        }

        $schema = array_merge($schema, $this->constraints($parameter));

        if ($parameter->example !== null) {
            $schema['examples'] = [$parameter->example];
        }

        if ($parameter->nullable) {
            $schema = $this->makeNullable($schema);
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    protected function baseType(Parameter $parameter): array
    {
        $type = $parameter->type;

        if (str_ends_with($type, '[]')) {
            $itemType = substr($type, 0, -2);

            $items = $itemType === 'object'
                ? ($parameter->children === [] ? ['type' => 'object'] : $this->objectFromParameters($parameter->children))
                : $this->scalarSchema($itemType, $parameter);

            return ['type' => 'array', 'items' => $items];
        }

        if ($type === 'object') {
            return $parameter->children === []
                ? ['type' => 'object']
                : $this->objectFromParameters($parameter->children);
        }

        if ($type === 'array') {
            return [
                'type' => 'array',
                'items' => $parameter->children === []
                    ? ['type' => 'string']
                    : $this->objectFromParameters($parameter->children),
            ];
        }

        return $this->scalarSchema($type, $parameter);
    }

    /** @return array<string, mixed> */
    protected function scalarSchema(string $type, Parameter $parameter): array
    {
        $schema = match ($type) {
            'integer' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'file' => ['type' => 'string', 'format' => 'binary'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'object' => ['type' => 'object'],
            default => ['type' => 'string'],
        };

        $format = $parameter->format ?? $this->formatFromRules($parameter->rules);

        if ($format !== null && ! isset($schema['format'])) {
            $schema['format'] = $format;
        }

        return $schema;
    }

    /** @param array<int, string> $rules */
    protected function formatFromRules(array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $format = match (Str::lower(Str::before($rule, ':'))) {
                'email' => 'email',
                'url', 'active_url' => 'uri',
                'uuid' => 'uuid',
                'ip', 'ipv4' => 'ipv4',
                'ipv6' => 'ipv6',
                'date' => 'date',
                'date_format' => 'date-time',
                default => null,
            };

            if ($format !== null) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Translate min/max/size/regex rules into JSON Schema keywords.
     *
     * @return array<string, mixed>
     */
    protected function constraints(Parameter $parameter): array
    {
        $constraints = [];
        $type = $parameter->type;
        $isNumeric = in_array($type, ['integer', 'number'], true);
        $isArray = $type === 'array' || str_ends_with($type, '[]');

        foreach ($parameter->rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $name = Str::lower(Str::before($rule, ':'));
            $value = Str::contains($rule, ':') ? Str::after($rule, ':') : null;
            $number = is_numeric($value) ? $value + 0 : null;

            if ($name === 'min' && $number !== null) {
                $constraints[$isNumeric ? 'minimum' : ($isArray ? 'minItems' : 'minLength')] = $number;
            } elseif ($name === 'max' && $number !== null) {
                $constraints[$isNumeric ? 'maximum' : ($isArray ? 'maxItems' : 'maxLength')] = $number;
            } elseif ($name === 'size' && $number !== null) {
                $keys = $isNumeric ? ['minimum', 'maximum'] : ($isArray ? ['minItems', 'maxItems'] : ['minLength', 'maxLength']);
                $constraints[$keys[0]] = $number;
                $constraints[$keys[1]] = $number;
            } elseif ($name === 'between' && $value !== null) {
                $this->applyBetween($constraints, $value, $isNumeric, $isArray);
            } elseif ($name === 'regex' && $value !== null) {
                $constraints['pattern'] = trim($value, '/');
            } elseif ($name === 'digits' && $number !== null) {
                $constraints['pattern'] = '^\d{' . (int) $number . '}$';
            }
        }

        return $constraints;
    }

    /** @param array<string, mixed> $constraints */
    protected function applyBetween(array &$constraints, string $value, bool $isNumeric, bool $isArray): void
    {
        $parts = array_map('trim', explode(',', $value));

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return;
        }

        [$min, $max] = [$parts[0] + 0, $parts[1] + 0];
        $keys = $isNumeric ? ['minimum', 'maximum'] : ($isArray ? ['minItems', 'maxItems'] : ['minLength', 'maxLength']);

        $constraints[$keys[0]] = $min;
        $constraints[$keys[1]] = $max;
    }

    /** @return array<int, mixed> */
    protected function castEnum(Parameter $parameter): array
    {
        return array_map(function ($value) use ($parameter) {
            if (! is_string($value)) {
                return $value;
            }

            return match ($parameter->type) {
                'integer' => (int) $value,
                'number' => (float) $value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
                default => $value,
            };
        }, $parameter->enum);
    }

    /**
     * Infer a schema from an example payload. Response bodies are known by
     * example rather than by declaration, so this is how they get a schema.
     *
     * @return array<string, mixed>
     */
    public function fromExample(mixed $example, int $depth = 0): array
    {
        if ($depth > 12 || $example === null) {
            return [];
        }

        if (is_bool($example)) {
            return ['type' => 'boolean', 'examples' => [$example]];
        }

        if (is_int($example)) {
            return ['type' => 'integer', 'examples' => [$example]];
        }

        if (is_float($example)) {
            return ['type' => 'number', 'examples' => [$example]];
        }

        if (is_string($example)) {
            $schema = ['type' => 'string'];
            $format = $this->formatFromValue($example);

            if ($format !== null) {
                $schema['format'] = $format;
            }

            $schema['examples'] = [$example];

            return $schema;
        }

        if (is_object($example)) {
            $example = (array) $example;
        }

        if (! is_array($example)) {
            return [];
        }

        if ($example === []) {
            return ['type' => 'array', 'items' => []];
        }

        if (array_is_list($example)) {
            return ['type' => 'array', 'items' => $this->fromExample($example[0], $depth + 1)];
        }

        $properties = [];

        foreach ($example as $key => $value) {
            $properties[(string) $key] = $this->fromExample($value, $depth + 1);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    public function formatFromValue(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1) {
            return 'date-time';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return 'date';
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return 'uuid';
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'email';
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return 'uri';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function makeNullable(array $schema): array
    {
        if (! $this->nullableAsType) {
            $schema['nullable'] = true;

            return $schema;
        }

        if (! isset($schema['type'])) {
            return $schema;
        }

        $types = (array) $schema['type'];

        if (! in_array('null', $types, true)) {
            $types[] = 'null';
        }

        $schema['type'] = $types;

        return $schema;
    }
}
