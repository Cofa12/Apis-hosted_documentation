<?php

namespace Cofa\ApiDocs\OpenApi;

/**
 * A readable wrapper around one JSON Schema node, with `$ref` resolution and
 * the helpers the Blade views need to render a parameter table.
 */
class SchemaObject
{
    /** @param array<string, mixed> $schema */
    public function __construct(
        protected array $schema = [],
        protected ?Spec $spec = null,
        public string $name = '',
        public bool $required = false,
    ) {
    }

    /** @param array<string, mixed> $schema */
    public static function make(array $schema, ?Spec $spec = null, string $name = '', bool $required = false): self
    {
        return new self($schema, $spec, $name, $required);
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->resolved();
    }

    /** @return array<string, mixed> */
    protected function resolved(int $depth = 0): array
    {
        $schema = $this->schema;

        while (isset($schema['$ref']) && is_string($schema['$ref']) && $depth < 10) {
            $target = $this->spec?->resolveRef($schema['$ref']);

            if ($target === null) {
                break;
            }

            $schema = array_merge($target, array_diff_key($schema, ['$ref' => null]));
            $depth++;
        }

        return $schema;
    }

    public function refName(): ?string
    {
        $ref = $this->schema['$ref'] ?? null;

        if (! is_string($ref)) {
            return null;
        }

        $parts = explode('/', $ref);

        return end($parts) ?: null;
    }

    /** The display type: "string", "integer[]", "object", "string | null". */
    public function type(): string
    {
        $schema = $this->resolved();
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $types = array_values(array_filter($type, fn ($item) => $item !== 'null'));
            $label = $types === [] ? 'null' : implode(' | ', $types);

            if (in_array('null', $type, true)) {
                $label .= ' | null';
            }

            $type = $label;
        }

        if ($type === null) {
            if (isset($schema['properties'])) {
                $type = 'object';
            } elseif (isset($schema['oneOf']) || isset($schema['anyOf'])) {
                $type = 'mixed';
            } else {
                $type = 'any';
            }
        }

        if ($type === 'array') {
            $items = $this->items();
            $itemType = $items?->type() ?? 'any';

            return $itemType === 'any' ? 'array' : $itemType . '[]';
        }

        $format = $schema['format'] ?? null;

        if (is_string($format) && in_array($format, ['binary', 'date', 'date-time', 'uuid', 'email', 'uri'], true)) {
            return $format === 'binary' ? 'file' : $type . '<' . $format . '>';
        }

        return (string) $type;
    }

    public function baseType(): string
    {
        $type = $this->resolved()['type'] ?? 'any';

        if (is_array($type)) {
            $types = array_values(array_filter($type, fn ($item) => $item !== 'null'));

            return (string) ($types[0] ?? 'null');
        }

        return (string) $type;
    }

    public function format(): ?string
    {
        $format = $this->resolved()['format'] ?? null;

        return is_string($format) ? $format : null;
    }

    public function description(): string
    {
        $description = $this->resolved()['description'] ?? '';

        return is_string($description) ? $description : '';
    }

    public function isNullable(): bool
    {
        $type = $this->resolved()['type'] ?? null;

        return is_array($type) && in_array('null', $type, true);
    }

    public function isObject(): bool
    {
        return $this->baseType() === 'object' || isset($this->resolved()['properties']);
    }

    public function isArray(): bool
    {
        return $this->baseType() === 'array';
    }

    /** @return array<int, mixed> */
    public function enum(): array
    {
        $enum = $this->resolved()['enum'] ?? [];

        return is_array($enum) ? $enum : [];
    }

    public function default(): mixed
    {
        return $this->resolved()['default'] ?? null;
    }

    /** @return array<string, SchemaObject> */
    public function properties(): array
    {
        $schema = $this->resolved();
        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return [];
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $result = [];

        foreach ($properties as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $result[(string) $name] = new self(
                $definition,
                $this->spec,
                (string) $name,
                in_array($name, $required, true)
            );
        }

        return $result;
    }

    public function items(): ?self
    {
        $items = $this->resolved()['items'] ?? null;

        return is_array($items) ? new self($items, $this->spec, $this->name, false) : null;
    }

    /**
     * Human readable constraints, ready to render as small pills.
     *
     * @return array<int, string>
     */
    public function constraints(): array
    {
        $schema = $this->resolved();
        $labels = [];

        $map = [
            'minLength' => 'min length %s',
            'maxLength' => 'max length %s',
            'minimum' => 'min %s',
            'maximum' => 'max %s',
            'minItems' => 'min %s items',
            'maxItems' => 'max %s items',
            'pattern' => 'pattern %s',
        ];

        foreach ($map as $key => $template) {
            if (isset($schema[$key]) && is_scalar($schema[$key])) {
                $labels[] = sprintf($template, (string) $schema[$key]);
            }
        }

        if ($this->isNullable()) {
            $labels[] = 'nullable';
        }

        return $labels;
    }

    /** The example for this node, generated from the schema when absent. */
    public function example(int $depth = 0): mixed
    {
        $schema = $this->resolved();

        if (isset($schema['examples']) && is_array($schema['examples']) && $schema['examples'] !== []) {
            return $schema['examples'][0];
        }

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if ($depth > 8) {
            return null;
        }

        if ($this->isObject()) {
            $example = [];

            foreach ($this->properties() as $name => $property) {
                $example[$name] = $property->example($depth + 1);
            }

            return $example;
        }

        if ($this->isArray()) {
            $items = $this->items();

            return $items === null ? [] : [$items->example($depth + 1)];
        }

        $enum = $this->enum();

        if ($enum !== []) {
            return $enum[0];
        }

        return match ($this->baseType()) {
            'integer' => 0,
            'number' => 0.0,
            'boolean' => true,
            'null' => null,
            default => 'string',
        };
    }

    /**
     * Flatten the schema into table rows: one per (possibly nested) property.
     *
     * @return array<int, array{name: string, depth: int, schema: SchemaObject, required: bool, path: string}>
     */
    public function rows(int $depth = 0, string $prefix = '', int $maxDepth = 6): array
    {
        if ($depth > $maxDepth) {
            return [];
        }

        $rows = [];
        $target = $this;

        if ($this->isArray()) {
            $items = $this->items();

            if ($items !== null && $items->isObject()) {
                $target = $items;
            }
        }

        foreach ($target->properties() as $name => $property) {
            $path = $prefix === '' ? $name : $prefix . '.' . $name;

            $rows[] = [
                'name' => $name,
                'depth' => $depth,
                'schema' => $property,
                'required' => $property->required,
                'path' => $path,
            ];

            if ($property->isObject() || ($property->isArray() && $property->items()?->isObject())) {
                $rows = array_merge($rows, $property->rows($depth + 1, $path, $maxDepth));
            }
        }

        return $rows;
    }

    public function isEmpty(): bool
    {
        return $this->resolved() === [];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->resolved(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
