<?php

namespace Cofa\ApiDocs\Tests;

use Cofa\ApiDocs\ApiDocsServiceProvider;
use Cofa\ApiDocs\Tests\Fixtures\Controllers\HealthController;
use Cofa\ApiDocs\Tests\Fixtures\Controllers\LegacyController;
use Cofa\ApiDocs\Tests\Fixtures\Controllers\UserController;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ApiDocsServiceProvider::class];
    }

    /** @var array<string, mixed> config applied on top of the defaults */
    protected array $overrides = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://api.example.test');
        $app['config']->set('api-docs.base_url', 'https://api.example.test');
        $app['config']->set('api-docs.title', 'Example API');
        $app['config']->set('api-docs.version', '2.4.0');
        $app['config']->set('api-docs.cache.enabled', false);

        foreach ($this->overrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Rebuild the application with extra configuration. Config that changes
     * route registration has to be in place before the provider boots.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function withConfig(array $overrides): static
    {
        $this->overrides = array_merge($this->overrides, $overrides);

        $this->refreshApplication();

        return $this;
    }

    /** The fixture API every test scans. */
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('api')->prefix('api')->group(function (Router $router) {
            $router->get('users', [UserController::class, 'index']);
            $router->post('users', [UserController::class, 'store'])->middleware(['auth:sanctum', 'throttle:60,1']);
            $router->get('users/{user}', [UserController::class, 'show'])->name('users.show');
            $router->put('users/{user}', [UserController::class, 'update'])->middleware('auth:sanctum');
            $router->delete('users/{user}', [UserController::class, 'destroy'])->middleware('auth:sanctum');
            $router->get('users/internal', [UserController::class, 'internal']);
            $router->get('health', HealthController::class);
            $router->post('legacy/export', [LegacyController::class, 'export']);
            $router->post('reports/{report}', [\Cofa\ApiDocs\Tests\Fixtures\Controllers\ReportController::class, 'store']);
            $router->put('conflicts', [\Cofa\ApiDocs\Tests\Fixtures\Controllers\ConflictController::class, 'update']);
            $router->put('agreements', [\Cofa\ApiDocs\Tests\Fixtures\Controllers\ConflictController::class, 'agree']);
            $router->get('ping', fn () => ['pong' => true]);
        });

        $router->get('web/home', fn () => 'home');
    }

    /** @return array<int, \Cofa\ApiDocs\Data\Endpoint> */
    protected function endpoints(): array
    {
        return $this->app->make(\Cofa\ApiDocs\DocumentationGenerator::class)->endpoints();
    }

    protected function endpoint(string $method, string $uri): \Cofa\ApiDocs\Data\Endpoint
    {
        foreach ($this->endpoints() as $endpoint) {
            if ($endpoint->uri === trim($uri, '/') && in_array(strtoupper($method), $endpoint->methods, true)) {
                return $endpoint;
            }
        }

        $this->fail("No documented endpoint for [{$method} {$uri}].");
    }

    protected function spec(): \Cofa\ApiDocs\OpenApi\Spec
    {
        return $this->app->make(\Cofa\ApiDocs\DocumentationGenerator::class)->generate();
    }
}
