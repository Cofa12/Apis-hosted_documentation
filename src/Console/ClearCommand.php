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
        if ($generator->forgetCache(force: true)) {
            $this->components->info('The cached API documentation was cleared.');

            return self::SUCCESS;
        }

        if (($error = $generator->cacheError()) !== null) {
            $this->components->warn('The cache store could not be reached: ' . $error);

            // Nothing can have been cached while caching is off, so this is
            // only a real failure when the feature is actually in use.
            return $generator->cacheEnabled() ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info('Nothing to clear: no cache store is configured.');

        return self::SUCCESS;
    }
}
