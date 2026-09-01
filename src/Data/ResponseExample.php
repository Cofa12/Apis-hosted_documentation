<?php

namespace Cofa\ApiDocs\Data;

use JsonSerializable;

class ResponseExample implements JsonSerializable
{
    public function __construct(
        public int $status = 200,
        public mixed $content = null,
        public string $description = '',
        public string $contentType = 'application/json',
        public array $headers = [],
        /** Component schema this body maps to, e.g. "UserResource". */
        public ?string $schemaName = null,
        /** True when the body is a list of the named schema. */
        public bool $collection = false,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Pretty printed body, ready to drop into a <pre> block. */
    public function body(): string
    {
        if ($this->content === null) {
            return '';
        }

        if (is_string($this->content)) {
            return $this->content;
        }

        return (string) json_encode(
            $this->content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public function statusText(): string
    {
        return self::TEXTS[$this->status] ?? 'Response';
    }

    public const TEXTS = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        410 => 'Gone',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'content' => $this->content,
            'description' => $this->description,
            'content_type' => $this->contentType,
            'headers' => $this->headers,
            'schema_name' => $this->schemaName,
            'collection' => $this->collection,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['status'] ?? 200),
            $data['content'] ?? null,
            $data['description'] ?? '',
            $data['content_type'] ?? 'application/json',
            $data['headers'] ?? [],
            $data['schema_name'] ?? null,
            (bool) ($data['collection'] ?? false),
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
