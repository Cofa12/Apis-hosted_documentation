<?php

namespace Cofa\ApiDocs\Scanning;

use Cofa\ApiDocs\Attributes\HideFromDocs;
use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Throwable;

/**
 * Walks Laravel's route collection and turns every matching route into a
 * documented endpoint.
 *
 * Reading the router (instead of scanning directories) is what makes the
 * package structure agnostic: modular monoliths, package provided routes,
 * invokable controllers and closures are all registered there by the time the
 * scan runs, no matter which file they came from.
 */
class RouteScanner
{
    /** @var array<int, array{route: string, error: string}> */
    protected array $errors = [];

    /** @var array<int, Conflict> */
    protected array $conflicts = [];

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, Extractor>  $extractors
     */
    public function __construct(
        protected Router $router,
        protected array $config = [],
        protected array $extractors = [],
    ) {
    }

    /** @param array<int, Extractor> $extractors */
    public function setExtractors(array $extractors): self
    {
        $this->extractors = $extractors;

        return $this;
    }

    /** @return array<int, Endpoint> */
    public function scan(): array
    {
        $endpoints = [];
        $this->conflicts = [];

        foreach ($this->routes() as $route) {
            $context = RouteContext::forRoute($route);

            if ($this->isHidden($context)) {
                continue;
            }

            $endpoint = new Endpoint($context->methods(), $context->uri());

            foreach ($this->extractors as $extractor) {
                try {
                    $extractor->extract($endpoint, $context);
                } catch (Throwable $exception) {
                    // One unreadable action must not take the whole scan down.
                    $this->errors[] = [
                        'route' => $route->uri(),
                        'error' => $extractor::class . ': ' . $exception->getMessage(),
                    ];
                }
            }

            foreach ($endpoint->meta['conflicts'] ?? [] as $conflict) {
                $this->conflicts[] = Conflict::fromArray($conflict);
            }

            $endpoints[] = $endpoint;
        }

        return $endpoints;
    }

    /**
     * Documentation that two sources described differently.
     *
     * @return array<int, Conflict>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return array<int, Route> */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
            if ($this->shouldDocument($route)) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    public function shouldDocument(Route $route): bool
    {
        $uri = trim($route->uri(), '/');

        $methods = array_diff(
            $route->methods(),
            (array) data_get($this->config, 'routes.skip_methods', ['HEAD', 'OPTIONS'])
        );

        if ($methods === []) {
            return false;
        }

        $include = (array) data_get($this->config, 'routes.include', ['api/*']);

        if ($include !== [] && ! $this->matchesAny($uri, $include)) {
            return false;
        }

        $exclude = (array) data_get($this->config, 'routes.exclude', []);

        if ($exclude !== [] && $this->matchesAny($uri, $exclude)) {
            return false;
        }

        $required = (array) data_get($this->config, 'routes.middleware', []);

        if ($required !== [] && ! $this->hasMiddleware($route, $required)) {
            return false;
        }

        if (data_get($this->config, 'routes.skip_closures', false) && ! is_string($route->getAction('uses'))
            && ! is_array($route->getAction('uses'))) {
            return false;
        }

        return true;
    }

    /** @param array<int, string> $patterns */
    protected function matchesAny(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern, '/');

            if ($uri === $pattern || Str::is($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $required */
    protected function hasMiddleware(Route $route, array $required): bool
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (Throwable) {
            $middleware = (array) ($route->getAction('middleware') ?? []);
        }

        foreach ($required as $needle) {
            foreach ($middleware as $item) {
                if (is_string($item) && ($item === $needle || Str::startsWith($item, $needle . ':'))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isHidden(RouteContext $context): bool
    {
        if ($context->attribute(HideFromDocs::class) !== null) {
            return true;
        }

        foreach (['ignore', 'hidefromdocs', 'hidden', 'internal'] as $tag) {
            if ($context->methodDocBlock()->hasTag($tag) || $context->classDocBlock()->hasTag($tag)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{route: string, error: string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
