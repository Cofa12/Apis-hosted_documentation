<?php

namespace Cofa\ApiDocs;

use Cofa\ApiDocs\Console\ClearCommand;
use Cofa\ApiDocs\Console\ExportCommand;
use Cofa\ApiDocs\Console\GenerateCommand;
use Cofa\ApiDocs\Console\HistoryCommand;
use Cofa\ApiDocs\History\HistoryStore;
use Cofa\ApiDocs\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ApiDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-docs.php', 'api-docs');

        $this->app->singleton(Tenancy::class, function ($app) {
            return new Tenancy((array) $app['config']->get('api-docs', []));
        });

        $this->app->singleton(DocumentationGenerator::class, function ($app) {
            $cache = null;

            try {
                $cache = $app->make(CacheRepository::class);
            } catch (Throwable) {
                // Caching is optional – the generator works without it.
            }

            return new DocumentationGenerator(
                $app->make(Router::class),
                (array) $app['config']->get('api-docs', []),
                $cache,
                $app->make(Tenancy::class),
            );
        });

        $this->app->singleton(HistoryStore::class, function ($app) {
            return new HistoryStore(
                $app->make(Filesystem::class),
                (array) $app['config']->get('api-docs', []),
                $app->basePath(),
                null,
                $app->make(Tenancy::class),
            );
        });

        $this->app->alias(DocumentationGenerator::class, 'api-docs');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'api-docs');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/api-docs.php' => $this->app->configPath('api-docs.php'),
            ], 'api-docs-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/api-docs'),
            ], 'api-docs-views');

            $this->commands([
                GenerateCommand::class,
                ExportCommand::class,
                HistoryCommand::class,
                ClearCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        $config = (array) $this->app['config']->get('api-docs.serve', []);

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $attributes = array_filter([
            'middleware' => $config['middleware'] ?? ['web'],
            'domain' => $config['domain'] ?? null,
        ], fn ($value) => $value !== null);

        Route::group($attributes, function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/docs.php');
        });
    }
}
