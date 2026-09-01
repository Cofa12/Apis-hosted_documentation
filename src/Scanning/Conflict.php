<?php

namespace Cofa\ApiDocs\Scanning;

use JsonSerializable;

/**
 * Two sources of hand written documentation describing the same thing
 * differently. The attribute wins; this records what was overruled so the
 * disagreement is visible instead of silently resolved.
 */
class Conflict implements JsonSerializable
{
    public function __construct(
        public string $handler,
        public string $location,
        public ?string $field,
        public string $property,
        public mixed $docblock,
        public mixed $attribute,
        public string $attributeName = 'ApiParam',
    ) {
    }

    public function message(): string
    {
        $subject = $this->field === null
            ? $this->location
            : $this->location . ' `' . $this->field . '`';

        // "(required: true vs false)" reads better than repeating the property
        // when the property is the subject already.
        $difference = $this->field === null
            ? $this->render($this->docblock) . ' vs ' . $this->render($this->attribute)
            : $this->property . ': ' . $this->render($this->docblock) . ' vs ' . $this->render($this->attribute);

        return $this->handler . ' — ' . $subject
            . ': docblock and #[' . $this->attributeName . '] disagree'
            . ' (' . $difference . '). Using attribute value.';
    }

    protected function render(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => '"' . (string) $value . '"',
        };
    }

    public function toArray(): array
    {
        return [
            'handler' => $this->handler,
            'location' => $this->location,
            'field' => $this->field,
            'property' => $this->property,
            'docblock' => $this->docblock,
            'attribute' => $this->attribute,
            'attribute_name' => $this->attributeName,
            'message' => $this->message(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['handler'] ?? '',
            $data['location'] ?? '',
            $data['field'] ?? null,
            $data['property'] ?? '',
            $data['docblock'] ?? null,
            $data['attribute'] ?? null,
            $data['attribute_name'] ?? 'ApiParam',
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
