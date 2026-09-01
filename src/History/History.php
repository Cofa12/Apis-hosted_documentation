<?php

namespace Cofa\ApiDocs\History;

use JsonSerializable;

/**
 * The recorded timeline of an API, plus the snapshot the next comparison runs
 * against.
 */
class History implements JsonSerializable
{
    public const VERSION = 1;

    /**
     * @param  array<int, Revision>  $revisions  newest last
     * @param  array<string, mixed>  $snapshot  the last recorded OpenAPI document
     */
    public function __construct(
        public array $revisions = [],
        public array $snapshot = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->revisions === [];
    }

    public function count(): int
    {
        return count($this->revisions);
    }

    /** Newest first, which is the order the UI wants. */
    public function latest(?int $limit = null): array
    {
        $revisions = array_reverse($this->revisions);

        return $limit === null ? $revisions : array_slice($revisions, 0, $limit);
    }

    public function nextNumber(): int
    {
        $last = end($this->revisions);

        return $last === false ? 1 : $last->number + 1;
    }

    public function add(Revision $revision, ?int $keep = null): self
    {
        $this->revisions[] = $revision;

        if ($keep !== null && $keep > 0 && count($this->revisions) > $keep) {
            $this->revisions = array_slice($this->revisions, -$keep);
        }

        return $this;
    }

    /**
     * Every recorded change for one endpoint, newest first.
     *
     * @return array<int, array{revision: Revision, operation: OperationChange}>
     */
    public function forOperation(string $method, string $path, ?int $limit = null): array
    {
        $key = strtoupper($method) . ' ' . $path;
        $entries = [];

        foreach ($this->latest() as $revision) {
            foreach ($revision->operations as $operation) {
                if ($operation->key() === $key) {
                    $entries[] = ['revision' => $revision, 'operation' => $operation];
                }
            }

            if ($limit !== null && count($entries) >= $limit) {
                break;
            }
        }

        return $limit === null ? $entries : array_slice($entries, 0, $limit);
    }

    /** When this endpoint last changed, or null if it has no history. */
    public function lastChangedAt(string $method, string $path): ?string
    {
        $entries = $this->forOperation($method, $path, 1);

        return $entries === [] ? null : $entries[0]['revision']->recordedAt;
    }

    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'revisions' => array_map(fn (Revision $r) => $r->toArray(), $this->revisions),
            'snapshot' => $this->snapshot,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            array_map(fn (array $r) => Revision::fromArray($r), $data['revisions'] ?? []),
            is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [],
        );
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode($this->toArray(), $flags);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
