<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Throwable;

/**
 * Adds the headers every request needs: the configured defaults, a content
 * type when the endpoint accepts a body, the tenant header when the route is
 * behind tenant middleware, and authorization when the route is behind auth
 * middleware.
 */
class HeaderExtractor implements Extractor
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = [])
    {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        foreach ((array) data_get($this->config, 'headers.defaults', []) as $name => $value) {
            $endpoint->addHeader(new HeaderParam((string) $name, (string) $value, true));
        }

        if ($endpoint->hasBody()) {
            foreach ((array) data_get($this->config, 'headers.body', []) as $name => $value) {
                $endpoint->addHeader(new HeaderParam((string) $name, (string) $value, true));
            }
        }

        $this->addTenantHeader($endpoint);

        if ($endpoint->authenticated) {
            $endpoint->addHeader(new HeaderParam(
                name: (string) data_get($this->config, 'auth.header', 'Authorization'),
                value: (string) data_get($this->config, 'auth.value', 'Bearer {YOUR_API_TOKEN}'),
                required: true,
                description: (string) data_get($this->config, 'auth.description', ''),
            ));
        }

        // File uploads need a multipart content type instead of JSON.
        if ($this->hasFileParameter($endpoint)) {
            $endpoint->addHeader(new HeaderParam(
                'Content-Type',
                'multipart/form-data',
                true,
                'This endpoint accepts file uploads.'
            ));
            $endpoint->meta['multipart'] = true;
        }
    }

    /**
     * Tenants identified by a request header (X-Tenant and friends) need that
     * header on every tenant route, or the try-it console cannot reach them.
     */
    protected function addTenantHeader(Endpoint $endpoint): void
    {
        $name = trim((string) data_get($this->config, 'tenancy.header.name', ''));
        $middleware = array_values(array_filter(
            (array) data_get($this->config, 'tenancy.header.middleware', []),
            'is_string'
        ));

        if ($name === '') {
            $name = (string) $this->stanclHeader($endpoint->middleware);

            if ($name === '') {
                return;
            }
        } elseif ($middleware !== [] && ! $this->usesMiddleware($endpoint->middleware, $middleware)) {
            return;
        }

        // A header documented on the endpoint itself wins over the generic one.
        foreach ($endpoint->headers as $existing) {
            if (strcasecmp($existing->name, $name) === 0) {
                return;
            }
        }

        $endpoint->addHeader(new HeaderParam(
            name: $name,
            value: (string) data_get($this->config, 'tenancy.header.value', 'your-tenant'),
            required: true,
            description: (string) data_get($this->config, 'tenancy.header.description', ''),
        ));
    }

    /**
     * Match by alias, by class name or by class basename, so the config can
     * list middleware however the routes happen to declare it.
     *
     * @param  array<int, string>  $routeMiddleware
     * @param  array<int, string>  $needles
     */
    protected function usesMiddleware(array $routeMiddleware, array $needles): bool
    {
        $aliases = $this->middlewareAliases();

        foreach ($needles as $needle) {
            if (isset($aliases[$needle])) {
                $needles[] = $aliases[$needle];
            }
        }

        foreach ($routeMiddleware as $item) {
            $name = explode(':', $item, 2)[0];
            $candidates = array_filter([$name, $aliases[$name] ?? null]);

            foreach ($candidates as $candidate) {
                $candidate = ltrim($candidate, '\\');

                foreach ($needles as $needle) {
                    $needle = ltrim($needle, '\\');

                    if (strcasecmp($candidate, $needle) === 0 || strcasecmp(class_basename($candidate), $needle) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * stancl/tenancy reads the tenant from a header it declares on its
     * request data middleware, so that route needs no configuration.
     *
     * @param  array<int, string>  $routeMiddleware
     */
    protected function stanclHeader(array $routeMiddleware): ?string
    {
        $class = 'Stancl\\Tenancy\\Middleware\\InitializeTenancyByRequestData';

        if (! class_exists($class) || ! $this->usesMiddleware($routeMiddleware, [$class])) {
            return null;
        }

        return property_exists($class, 'header') && is_string($class::$header) ? $class::$header : null;
    }

    /** @return array<string, string> */
    protected function middlewareAliases(): array
    {
        try {
            $router = function_exists('app') ? app('router') : null;
            $aliases = $router !== null && method_exists($router, 'getMiddleware') ? $router->getMiddleware() : [];
        } catch (Throwable) {
            return [];
        }

        return array_filter($aliases, 'is_string');
    }

    protected function hasFileParameter(Endpoint $endpoint): bool
    {
        foreach ($endpoint->bodyParameters as $parameter) {
            if ($this->isFile($parameter)) {
                return true;
            }
        }

        return false;
    }

    protected function isFile(Parameter $parameter): bool
    {
        if ($parameter->type === 'file' || $parameter->type === 'file[]') {
            return true;
        }

        foreach ($parameter->children as $child) {
            if ($this->isFile($child)) {
                return true;
            }
        }

        return false;
    }
}
