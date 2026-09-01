<?php

namespace Cofa\ApiDocs\Data;

use JsonSerializable;

class HeaderParam implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $value = '',
        public bool $required = false,
        public string $description = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'required' => $this->required,
            'description' => $this->description,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['value'] ?? '',
            (bool) ($data['required'] ?? false),
            $data['description'] ?? '',
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
