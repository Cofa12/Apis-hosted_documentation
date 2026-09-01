<?php

namespace Cofa\ApiDocs\Console;

use Cofa\ApiDocs\DocumentationGenerator;
use Cofa\ApiDocs\History\HistoryStore;
use Cofa\ApiDocs\OpenApi\CodeSampleGenerator;
use Cofa\ApiDocs\Writers\BladeWriter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateCommand extends Command
{
    protected $signature = 'api-docs:generate
        {--force : Overwrite Blade templates that already exist in the project}
        {--no-views : Only write the OpenAPI document, leave the Blade templates alone}
        {--static : Also render a standalone HTML file}
        {--no-history : Skip recording a revision in the endpoint history}
        {--spec= : Write the OpenAPI document to a custom path}';

    protected $description = 'Scan every API route and write the Blade documentation into this project';

    public function handle(DocumentationGenerator $generator, Filesystem $files, HistoryStore $history): int
    {
        $config = $generator->config();

        $this->components->info('Scanning routes…');

        $spec = $generator->generate();
        $writer = new BladeWriter($files, $config, base_path(), '', $generator->tenancy());

        $specPath = $writer->writeSpec($spec, $this->option('spec') ?: null);
        $this->components->twoColumnDetail('OpenAPI document', $writer->relative($specPath));

        if (! $this->option('no-views')) {
            $written = $writer->writeViews((bool) $this->option('force'));

            $this->components->twoColumnDetail(
                'Blade templates',
                $written === []
                    ? 'already present (use --force to overwrite)'
                    : count($written) . ' file' . (count($written) === 1 ? '' : 's') . ' written'
            );
        }

        $revision = $this->option('no-history') ? null : $history->record($spec);

        if (! $this->option('no-history') && $history->enabled()) {
            $this->components->twoColumnDetail(
                'History',
                $revision === null
                    ? 'no changes since the last run'
                    : $revision->id() . ' · ' . $revision->headline() . ($revision->isBreaking() ? ' (breaking)' : '')
            );
        }

        if ($this->option('static')) {
            $html = view('api-docs::documentation', [
                'spec' => $spec,
                'ui' => (array) data_get($config, 'ui', []),
                'samples' => new CodeSampleGenerator((array) data_get($config, 'code_samples', ['curl'])),
                'baseUrl' => rtrim((string) data_get($config, 'base_url', $spec->baseUrl()), '/'),
                'history' => $history->load(),
                'historyOptions' => (array) data_get($config, 'history', []),
            ])->render();

            $staticPath = $writer->writeStatic($html);
            $this->components->twoColumnDetail('Static build', $writer->relative($staticPath));
        }

        $generator->forgetCache();

        $errors = $generator->errors();

        if ($errors !== []) {
            $this->newLine();
            $this->components->warn(count($errors) . ' route(s) could not be fully analysed:');

            foreach (array_slice($errors, 0, 10) as $error) {
                $this->components->bulletList([$error['route'] . ' — ' . $error['error']]);
            }
        }

        if ($revision !== null && ! $revision->initial) {
            $this->newLine();

            foreach (array_slice($revision->operations, 0, 10) as $operation) {
                $this->line(sprintf(
                    '  <fg=%s>%-8s</> %s %s',
                    match ($operation->type) { 'added' => 'green', 'removed' => 'red', default => 'yellow' },
                    $operation->label(),
                    $operation->method,
                    $operation->path,
                ));
            }

            if ($revision->count() > 10) {
                $this->line('  <fg=gray>… and ' . ($revision->count() - 10) . ' more (see `php artisan api-docs:history`)</>');
            }
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Documented %d endpoint%s across %d group%s.',
            $spec->operationCount(),
            $spec->operationCount() === 1 ? '' : 's',
            count($spec->groupedOperations()),
            count($spec->groupedOperations()) === 1 ? '' : 's',
        ));

        $path = trim((string) data_get($config, 'serve.path', 'api/documentation'), '/');

        if (data_get($config, 'serve.enabled', true) && $path !== '') {
            $this->line('  <fg=gray>Browse it at</> <fg=cyan>/' . $path . '</>');
        }

        return self::SUCCESS;
    }
}
