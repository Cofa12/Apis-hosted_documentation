<?php

namespace Cofa\ApiDocs\History;

use Illuminate\Support\Str;
use JsonSerializable;

/**
 * Everything that changed about one endpoint in a single revision.
 */
class OperationChange implements JsonSerializable
{
    /** @param array<int, Change> $changes */
    public function __construct(
        public string $type,
        public string $method,
        public string $path,
        public array $changes = [],
        public string $group = '',
        public string $summary = '',
    ) {
    }

    /** Matches the anchor the documentation page uses for this endpoint. */
    public function id(): string
    {
        return Str::slug($this->method . '-' . str_replace(['{', '}', '/'], ['', '', '-'], $this->path)) ?: 'operation';
    }

    public function key(): string
    {
        return strtoupper($this->method) . ' ' . $this->path;
    }

    public function isBreaking(): bool
    {
        if ($this->type === Change::REMOVED) {
            return true;
        }

        foreach ($this->changes as $change) {
            if ($change->isBreaking()) {
                return true;
            }
        }

        return false;
    }

    public function label(): string
    {
        return match ($this->type) {
            Change::ADDED => 'Added',
            Change::REMOVED => 'Removed',
            default => 'Changed',
        };
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'method' => $this->method,
            'path' => $this->path,
            'group' => $this->group,
            'summary' => $this->summary,
            'changes' => array_map(fn (Change $change) => $change->toArray(), $this->changes),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'] ?? Change::MODIFIED,
            $data['method'] ?? 'GET',
            $data['path'] ?? '/',
            array_map(fn (array $change) => Change::fromArray($change), $data['changes'] ?? []),
            $data['group'] ?? '',
            $data['summary'] ?? '',
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
