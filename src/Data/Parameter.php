<?php

namespace Cofa\ApiDocs\Data;

use JsonSerializable;

/**
 * A single documented input: a body field, a query string value or a URL segment.
 */
class Parameter implements JsonSerializable
{
    /** @param array<int, Parameter> $children */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public string $description = '',
        public mixed $example = null,
        public array $children = [],
        public array $rules = [],
        public array $enum = [],
        public mixed $default = null,
        public bool $nullable = false,
        public ?string $format = null,
    ) {
    }

    public static function make(string $name, string $type = 'string'): self
    {
        return new self($name, $type);
    }

    public function withDescription(string $description): self
    {
        $this->description = trim($description);

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function example(mixed $example): self
    {
        $this->example = $example;

        return $this;
    }

    /** The dotted name without the wildcard segments, useful for display. */
    public function label(): string
    {
        return $this->name;
    }

    public function isObject(): bool
    {
        return $this->type === 'object' || $this->type === 'object[]';
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * Merge another definition into this one. Values coming from the other
     * parameter only win when this one has nothing better to offer, which is
     * what lets an explicit annotation refine an inferred parameter.
     */
    public function mergeFrom(self $other, bool $preferOther = false): self
    {
        $take = static fn ($mine, $theirs, $empty = null) => $preferOther
            ? ($theirs !== $empty && $theirs !== null ? $theirs : $mine)
            : ($mine !== $empty && $mine !== null ? $mine : $theirs);

        $this->type = $take($this->type === 'string' ? null : $this->type, $other->type) ?? 'string';
        $this->description = (string) $take($this->description, $other->description, '');
        $this->example = $take($this->example, $other->example);
        $this->default = $take($this->default, $other->default);
        $this->format = $take($this->format, $other->format);
        $this->enum = $this->enum !== [] && ! $preferOther ? $this->enum : ($other->enum ?: $this->enum);
        $this->rules = array_values(array_unique(array_merge($this->rules, $other->rules)));
        $this->required = $preferOther ? $other->required : ($this->required || $other->required);
        $this->nullable = $this->nullable || $other->nullable;

        foreach ($other->children as $child) {
            $existing = null;
            foreach ($this->children as $mine) {
                if ($mine->name === $child->name) {
                    $existing = $mine;
                    break;
                }
            }

            if ($existing !== null) {
                $existing->mergeFrom($child, $preferOther);
            } else {
                $this->children[] = $child;
            }
        }

        return $this;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'description' => $this->description,
            'example' => $this->example,
            'children' => array_map(fn (Parameter $c) => $c->toArray(), $this->children),
            'rules' => $this->rules,
            'enum' => $this->enum,
            'default' => $this->default,
            'nullable' => $this->nullable,
            'format' => $this->format,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'] ?? 'string',
            required: (bool) ($data['required'] ?? false),
            description: $data['description'] ?? '',
            example: $data['example'] ?? null,
            children: array_map(fn (array $c) => self::fromArray($c), $data['children'] ?? []),
            rules: $data['rules'] ?? [],
            enum: $data['enum'] ?? [],
            default: $data['default'] ?? null,
            nullable: (bool) ($data['nullable'] ?? false),
            format: $data['format'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
