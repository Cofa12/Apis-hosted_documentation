<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Illuminate\Support\Str;

/**
 * The basics every endpoint has: verbs, URI, name, middleware, handler, group.
 */
class RouteMetadataExtractor implements Extractor
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = [])
    {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $endpoint->methods = $context->methods();
        $endpoint->uri = $context->uri();
        $endpoint->name = $context->route->getName();
        $endpoint->middleware = $context->middleware();
        $endpoint->controller = $context->controller;
        $endpoint->action = $context->action;
        $endpoint->authenticated = $this->isAuthenticated($endpoint->middleware);
        $endpoint->group = $this->resolveGroup($context);
        $endpoint->title = $this->resolveTitle($context);
        $endpoint->order = $this->resolveOrder($context);
    }

    /** @param array<int, string> $middleware */
    protected function isAuthenticated(array $middleware): bool
    {
        $needles = (array) data_get($this->config, 'auth.middleware', ['auth']);

        foreach ($middleware as $item) {
            foreach ($needles as $needle) {
                if ($item === $needle || Str::startsWith($item, $needle . ':')) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function resolveGroup(RouteContext $context): string
    {
        $default = (string) data_get($this->config, 'grouping.default', 'General');
        $strategy = (string) data_get($this->config, 'grouping.strategy', 'controller');

        if ($strategy === 'uri') {
            return $this->groupFromUri($context->uri()) ?? $default;
        }

        if ($context->controller !== null) {
            $name = Str::replaceLast('Controller', '', class_basename($context->controller));

            if ($name !== '') {
                return Str::headline(Str::plural($name));
            }
        }

        return $this->groupFromUri($context->uri()) ?? $default;
    }

    protected function groupFromUri(string $uri): ?string
    {
        $segments = array_values(array_filter(
            explode('/', $uri),
            fn (string $segment) => $segment !== '' && ! str_starts_with($segment, '{')
        ));

        // Skip conventional prefixes such as api/v1.
        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), ['api', 'admin'], true) || preg_match('/^v\d+$/i', $segment) === 1) {
                continue;
            }

            return Str::headline(Str::plural(str_replace(['-', '_'], ' ', $segment)));
        }

        return null;
    }

    /** A readable default title derived from the action name. */
    protected function resolveTitle(RouteContext $context): string
    {
        if ($context->action === null) {
            return '';
        }

        $resource = $context->controller === null
            ? 'resource'
            : Str::lower(Str::headline(Str::replaceLast('Controller', '', class_basename($context->controller))));

        $singular = Str::singular($resource);

        return match ($context->action) {
            'index' => 'List ' . Str::plural($resource),
            'store' => 'Create ' . $singular,
            'show' => 'Get ' . $singular,
            'update' => 'Update ' . $singular,
            'destroy' => 'Delete ' . $singular,
            'edit' => 'Edit ' . $singular,
            'create' => 'New ' . $singular,
            '__invoke' => Str::headline($resource),
            default => Str::ucfirst(Str::lower(Str::headline($context->action))),
        };
    }

    /** Keeps resourceful routes in their conventional order inside a group. */
    protected function resolveOrder(RouteContext $context): int
    {
        return match ($context->action) {
            'index' => 1,
            'store' => 2,
            'show' => 3,
            'update' => 4,
            'destroy' => 5,
            default => 10,
        };
    }
}
