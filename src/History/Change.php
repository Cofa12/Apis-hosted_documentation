<?php

namespace Cofa\ApiDocs\History;

use JsonSerializable;

/**
 * One difference between two versions of an endpoint.
 */
class Change implements JsonSerializable
{
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public const MODIFIED = 'modified';

    public function __construct(
        public string $type,
        public string $category,
        public string $target,
        public string $summary,
        public mixed $from = null,
        public mixed $to = null,
    ) {
    }

    public static function added(string $category, string $target, string $summary, mixed $to = null): self
    {
        return new self(self::ADDED, $category, $target, $summary, null, $to);
    }

    public static function removed(string $category, string $target, string $summary, mixed $from = null): self
    {
        return new self(self::REMOVED, $category, $target, $summary, $from, null);
    }

    public static function modified(string $category, string $target, string $summary, mixed $from = null, mixed $to = null): self
    {
        return new self(self::MODIFIED, $category, $target, $summary, $from, $to);
    }

    /** True when the change can break an existing client. */
    public function isBreaking(): bool
    {
        if ($this->type === self::REMOVED) {
            return in_array($this->category, ['endpoint', 'parameter', 'body', 'response'], true);
        }

        if ($this->category === 'auth' && $this->to === true) {
            return true;
        }

        // A field that was optional and is now required breaks existing callers.
        return $this->type === self::MODIFIED
            && in_array($this->category, ['parameter', 'body'], true)
            && str_ends_with($this->target, ':required')
            && $this->to === true;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'category' => $this->category,
            'target' => $this->target,
            'summary' => $this->summary,
            'from' => $this->from,
            'to' => $this->to,
        ], fn ($value) => $value !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'] ?? self::MODIFIED,
            $data['category'] ?? 'endpoint',
            $data['target'] ?? '',
            $data['summary'] ?? '',
            $data['from'] ?? null,
            $data['to'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
