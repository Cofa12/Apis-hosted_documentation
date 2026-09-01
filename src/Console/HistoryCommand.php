<?php

namespace Cofa\ApiDocs\Console;

use Cofa\ApiDocs\History\HistoryStore;
use Cofa\ApiDocs\History\OperationChange;
use Cofa\ApiDocs\History\Revision;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HistoryCommand extends Command
{
    protected $signature = 'api-docs:history
        {--limit=10 : How many revisions to show}
        {--endpoint= : Only show revisions touching endpoints matching this path}
        {--breaking : Only show revisions containing a breaking change}
        {--json : Output the raw history instead}';

    protected $description = 'Show what changed about the documented endpoints, and when';

    public function handle(HistoryStore $store): int
    {
        $history = $store->load();

        if ($this->option('json')) {
            $this->line($history->toJson());

            return self::SUCCESS;
        }

        if ($history->isEmpty()) {
            $this->components->warn('No history recorded yet. Run `php artisan api-docs:generate` first.');

            return self::SUCCESS;
        }

        $filter = (string) ($this->option('endpoint') ?? '');
        $breakingOnly = (bool) $this->option('breaking');
        $limit = max(1, (int) $this->option('limit'));
        $shown = 0;

        foreach ($history->latest() as $revision) {
            $operations = $this->filter($revision, $filter, $breakingOnly);

            if ($operations === []) {
                continue;
            }

            if ($shown >= $limit) {
                break;
            }

            $shown++;
            $this->renderRevision($revision, $operations);
        }

        if ($shown === 0) {
            $this->components->warn('No revisions matched.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info($history->count() . ' revision' . ($history->count() === 1 ? '' : 's') . ' recorded in ' . $store->path());

        return self::SUCCESS;
    }

    /** @return array<int, OperationChange> */
    protected function filter(Revision $revision, string $endpoint, bool $breakingOnly): array
    {
        return array_values(array_filter($revision->operations, function (OperationChange $operation) use ($endpoint, $breakingOnly) {
            if ($endpoint !== '' && ! Str::contains(Str::lower($operation->path), Str::lower($endpoint))) {
                return false;
            }

            return ! $breakingOnly || $operation->isBreaking();
        }));
    }

    /** @param array<int, OperationChange> $operations */
    protected function renderRevision(Revision $revision, array $operations): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  <fg=cyan;options=bold>%s</> <fg=gray>%s</>  %s%s',
            $revision->id(),
            $revision->recordedAt,
            $revision->headline(),
            $revision->isBreaking() ? ' <fg=red>(breaking)</>' : '',
        ));

        foreach ($operations as $operation) {
            $colour = match ($operation->type) {
                'added' => 'green',
                'removed' => 'red',
                default => 'yellow',
            };

            $this->line(sprintf(
                '    <fg=%s>%-8s</> <options=bold>%s</> %s',
                $colour,
                $operation->label(),
                $operation->method,
                $operation->path,
            ));

            if ($revision->initial) {
                continue;
            }

            foreach ($operation->changes as $change) {
                $this->line('        <fg=gray>·</> ' . $change->summary . ($change->isBreaking() ? ' <fg=red>[breaking]</>' : ''));
            }
        }
    }
}
