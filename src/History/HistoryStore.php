<?php

namespace Cofa\ApiDocs\History;

use Cofa\ApiDocs\OpenApi\Spec;
use Cofa\ApiDocs\Support\ProjectPath;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Reads and writes the endpoint history file, and records a new revision
 * whenever the documentation has changed since the last one.
 */
class HistoryStore
{
    protected SpecDiffer $differ;

    /** @param array<string, mixed> $config */
    public function __construct(
        protected Filesystem $files,
        protected array $config = [],
        protected string $basePath = '',
        ?SpecDiffer $differ = null,
    ) {
        $this->differ = $differ ?? new SpecDiffer();
    }

    public function enabled(): bool
    {
        return (bool) data_get($this->config, 'history.enabled', true);
    }

    public function path(): string
    {
        return ProjectPath::resolve(
            (string) data_get($this->config, 'history.path', 'resources/views/vendor/api-docs/history.json'),
            $this->basePath
        );
    }

    public function exists(): bool
    {
        return $this->files->exists($this->path());
    }

    public function load(): History
    {
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return new History();
        }

        try {
            $decoded = json_decode($this->files->get($path), true);
        } catch (Throwable) {
            return new History();
        }

        // A corrupted history must never take the documentation down with it.
        return is_array($decoded) ? History::fromArray($decoded) : new History();
    }

    public function save(History $history): string
    {
        $path = $this->path();

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $history->toJson() . "\n");

        return $path;
    }

    /**
     * Compare the given document against the last recorded snapshot and store
     * the difference as a new revision. Returns null when nothing changed.
     */
    public function record(Spec $spec, ?string $timestamp = null, ?string $note = null): ?Revision
    {
        if (! $this->enabled()) {
            return null;
        }

        $history = $this->load();
        $timestamp ??= $this->now();
        $initial = $history->snapshot === [];

        $operations = $initial
            ? $this->initialOperations($spec)
            : $this->differ->diff(Spec::fromArray($history->snapshot), $spec);

        if ($operations === []) {
            // Still refresh the snapshot: the document may differ in ways the
            // diff deliberately ignores, and we do not want to report those twice.
            $history->snapshot = $spec->toArray();
            $this->save($history);

            return null;
        }

        $revision = new Revision(
            number: $history->nextNumber(),
            recordedAt: $timestamp,
            version: $spec->version(),
            operations: $operations,
            initial: $initial,
            note: $note,
        );

        $history->add($revision, (int) data_get($this->config, 'history.keep', 50));
        $history->snapshot = $spec->toArray();

        $this->save($history);

        return $revision;
    }

    /** @return array<int, OperationChange> */
    protected function initialOperations(Spec $spec): array
    {
        $operations = [];

        foreach ($spec->operations() as $operation) {
            $operations[] = new OperationChange(
                Change::ADDED,
                $operation->method,
                $operation->path,
                [Change::added('endpoint', 'endpoint', 'Documented for the first time')],
                $operation->group(),
                $operation->summary(),
            );
        }

        return $operations;
    }

    public function forget(): bool
    {
        return $this->files->exists($this->path()) && $this->files->delete($this->path());
    }

    protected function now(): string
    {
        if (function_exists('now')) {
            try {
                return now()->toIso8601String();
            } catch (Throwable) {
                // fall through to the plain PHP clock
            }
        }

        return date('c');
    }
}
