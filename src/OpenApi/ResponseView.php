<?php

namespace Cofa\ApiDocs\OpenApi;

/**
 * One response of an operation, with its schema and rendered example body.
 */
class ResponseView
{
    /** @param array<string, mixed> $response */
    public function __construct(
        protected Spec $spec,
        public string $status,
        protected array $response = [],
    ) {
    }

    public function statusCode(): int
    {
        return is_numeric($this->status) ? (int) $this->status : 0;
    }

    public function isSuccessful(): bool
    {
        $code = $this->statusCode();

        if ($code === 0) {
            return $this->status === 'default';
        }

        return $code >= 200 && $code < 300;
    }

    public function sortKey(): int
    {
        return $this->statusCode() === 0 ? 999 : $this->statusCode();
    }

    public function description(): string
    {
        $description = $this->response['description'] ?? '';

        return is_string($description) ? $description : '';
    }

    public function mediaType(): ?string
    {
        $content = $this->response['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return null;
        }

        foreach (array_keys($content) as $type) {
            return (string) $type;
        }

        return null;
    }

    public function schema(): ?SchemaObject
    {
        $mediaType = $this->mediaType();

        if ($mediaType === null) {
            return null;
        }

        $schema = $this->response['content'][$mediaType]['schema'] ?? null;

        return is_array($schema) ? SchemaObject::make($schema, $this->spec) : null;
    }

    public function example(): mixed
    {
        $mediaType = $this->mediaType();

        if ($mediaType === null) {
            return null;
        }

        $media = $this->response['content'][$mediaType] ?? [];

        if (is_array($media) && array_key_exists('example', $media)) {
            return $media['example'];
        }

        if (is_array($media) && is_array($media['examples'] ?? null) && $media['examples'] !== []) {
            $first = reset($media['examples']);

            if (is_array($first) && array_key_exists('value', $first)) {
                return $first['value'];
            }
        }

        return $this->schema()?->example();
    }

    public function body(): string
    {
        $example = $this->example();

        if ($example === null) {
            return '';
        }

        if (is_string($example)) {
            return $example;
        }

        return (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<int, array{name: string, description: string, example: string}> */
    public function headers(): array
    {
        $headers = $this->response['headers'] ?? [];

        if (! is_array($headers)) {
            return [];
        }

        $result = [];

        foreach ($headers as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $schema = is_array($definition['schema'] ?? null)
                ? SchemaObject::make($definition['schema'], $this->spec)
                : null;

            $example = $schema?->example();

            $result[] = [
                'name' => (string) $name,
                'description' => (string) ($definition['description'] ?? ''),
                'example' => is_scalar($example) ? (string) $example : '',
            ];
        }

        return $result;
    }

    public function statusText(): string
    {
        return \Cofa\ApiDocs\Data\ResponseExample::TEXTS[$this->statusCode()] ?? 'Response';
    }
}
