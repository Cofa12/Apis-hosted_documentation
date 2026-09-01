<?php

namespace Cofa\ApiDocs\Console;

use Cofa\ApiDocs\DocumentationGenerator;
use Cofa\ApiDocs\Writers\BladeWriter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ExportCommand extends Command
{
    protected $signature = 'api-docs:export
        {path? : Where to write the document (defaults to the configured spec file)}
        {--format=json : json or yaml}
        {--print : Write the document to stdout instead of a file}';

    protected $description = 'Export the OpenAPI document for this application';

    public function handle(DocumentationGenerator $generator, Filesystem $files): int
    {
        $spec = $generator->generate();
        $format = strtolower((string) $this->option('format'));

        if ($this->option('print')) {
            $this->line($format === 'yaml' ? $spec->toYaml() : $spec->toJson());

            return self::SUCCESS;
        }

        $writer = new BladeWriter($files, $generator->config(), base_path(), '', $generator->tenancy());

        $path = $this->argument('path');

        if ($path === null && $format === 'yaml') {
            $path = 'openapi.yaml';
        }

        $target = $writer->writeSpec($spec, is_string($path) ? $path : null);

        $this->components->info('OpenAPI document written to ' . $writer->relative($target));

        return self::SUCCESS;
    }
}
