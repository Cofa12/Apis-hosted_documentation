<?php

namespace Cofa\ApiDocs\History;

use JsonSerializable;

/**
 * One recorded generation: what the API looked like at that point, and what
 * changed since the previous one.
 */
class Revision implements JsonSerializable
{
    /** @param array<int, OperationChange> $operations */
    public function __construct(
        public int $number,
        public string $recordedAt,
        public string $version = '',
        public array $operations = [],
        public bool $initial = false,
        public ?string $note = null,
    ) {
    }

    public function id(): string
    {
        return 'rev-' . $this->number;
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function count(): int
    {
        return count($this->operations);
    }

    /** @return array<int, OperationChange> */
    public function added(): array
    {
        return $this->ofType(Change::ADDED);
    }

    /** @return array<int, OperationChange> */
    public function removed(): array
    {
        return $this->ofType(Change::REMOVED);
    }

    /** @return array<int, OperationChange> */
    public function modified(): array
    {
        return $this->ofType(Change::MODIFIED);
    }

    /** @return array<int, OperationChange> */
    protected function ofType(string $type): array
    {
        return array_values(array_filter($this->operations, fn (OperationChange $o) => $o->type === $type));
    }

    public function isBreaking(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->isBreaking()) {
                return true;
            }
        }

        return false;
    }

    /** "3 added, 1 changed, 1 removed" */
    public function headline(): string
    {
        if ($this->initial) {
            return $this->count() . ' endpoint' . ($this->count() === 1 ? '' : 's') . ' documented';
        }

        $parts = [];

        foreach (['added' => count($this->added()), 'changed' => count($this->modified()), 'removed' => count($this->removed())] as $label => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . $label;
            }
        }

        return $parts === [] ? 'No changes' : implode(', ', $parts);
    }

    public function date(): string
    {
        return substr($this->recordedAt, 0, 10);
    }

    public function toArray(): array
    {
        return array_filter([
            'number' => $this->number,
            'recorded_at' => $this->recordedAt,
            'version' => $this->version,
            'initial' => $this->initial,
            'note' => $this->note,
            'operations' => array_map(fn (OperationChange $o) => $o->toArray(), $this->operations),
        ], fn ($value) => $value !== null && $value !== false && $value !== '');
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['number'] ?? 1),
            $data['recorded_at'] ?? '',
            $data['version'] ?? '',
            array_map(fn (array $o) => OperationChange::fromArray($o), $data['operations'] ?? []),
            (bool) ($data['initial'] ?? false),
            $data['note'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
