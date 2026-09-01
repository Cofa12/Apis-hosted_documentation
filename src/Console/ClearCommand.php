<?php

namespace Cofa\ApiDocs\Console;

use Cofa\ApiDocs\DocumentationGenerator;
use Illuminate\Console\Command;

class ClearCommand extends Command
{
    protected $signature = 'api-docs:clear';

    protected $description = 'Forget the cached API documentation';

    public function handle(DocumentationGenerator $generator): int
    {
        $generator->forgetCache();

        $this->components->info('The cached API documentation was cleared.');

        return self::SUCCESS;
    }
}
