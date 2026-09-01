<?php

namespace Cofa\ApiDocs\Tenancy;

use Throwable;

/**
 * Makes the generated artefacts tenant aware.
 *
 * Multi tenant applications serve the same routes from many contexts, so the
 * spec, the history file, the cache entry and the base URL all have to be
 * scoped per tenant. Anywhere a `{tenant}` placeholder appears in the
 * configuration it is replaced with the current tenant key.
 */
class Tenancy
{
    public const PLACEHOLDER = '{tenant}';

    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = [])
    {
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function enabled(): bool
    {
        return (bool) data_get($this->config, 'tenancy.enabled', false);
    }

    /** The current tenant key, or null in the central context. */
    public function key(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        foreach ([
            fn () => $this->fromResolver(),
            fn () => $this->fromStancl(),
            fn () => $this->fromSpatie(),
            fn () => $this->fromHost(),
        ] as $strategy) {
            try {
                $key = $strategy();
            } catch (Throwable) {
                // A tenancy package that is not initialised must not break the docs.
                continue;
            }

            if (is_string($key) && $key !== '') {
                return $this->sanitise($key);
            }

            if (is_int($key)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** The tenant key, falling back to the configured central key. */
    public function id(): string
    {
        return $this->key() ?? (string) data_get($this->config, 'tenancy.central_key', 'central');
    }

    public function isCentral(): bool
    {
        return $this->key() === null;
    }

    /** Replace the `{tenant}` placeholder in a single value. */
    public function apply(string $value): string
    {
        if (! str_contains($value, self::PLACEHOLDER)) {
            return $value;
        }

        return str_replace(self::PLACEHOLDER, $this->id(), $value);
    }

    /**
     * Replace the placeholder everywhere in a configuration array.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function applyToConfig(array $config): array
    {
        array_walk_recursive($config, function (&$value) {
            if (is_string($value)) {
                $value = $this->apply($value);
            }
        });

        return $config;
    }

    /**
     * Scope a cache key to the current tenant. Sharing one cached document
     * between tenants would leak one tenant's documentation into another.
     */
    public function cacheKey(string $key): string
    {
        if (! $this->enabled()) {
            return $key;
        }

        if (str_contains($key, self::PLACEHOLDER)) {
            return $this->apply($key);
        }

        return data_get($this->config, 'tenancy.scope_cache', true)
            ? $key . '.' . $this->id()
            : $key;
    }

    /** Whether the base URL should follow the host the docs are viewed on. */
    public function followsRequestHost(): bool
    {
        return $this->enabled() && (bool) data_get($this->config, 'tenancy.follow_request_host', true);
    }

    protected function fromResolver(): string|int|null
    {
        $resolver = data_get($this->config, 'tenancy.resolver');

        if ($resolver === null) {
            return null;
        }

        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = function_exists('app') ? app($resolver) : new $resolver();
        }

        if (! is_callable($resolver)) {
            return null;
        }

        $key = $resolver();

        return is_string($key) || is_int($key) ? $key : null;
    }

    /** stancl/tenancy exposes the current tenant through a global helper. */
    protected function fromStancl(): string|int|null
    {
        if (! function_exists('tenant')) {
            return null;
        }

        $tenant = tenant();

        if ($tenant === null) {
            return null;
        }

        if (is_object($tenant) && method_exists($tenant, 'getTenantKey')) {
            $key = $tenant->getTenantKey();

            return is_string($key) || is_int($key) ? $key : null;
        }

        return is_string($tenant) || is_int($tenant) ? $tenant : null;
    }

    /** spatie/laravel-multitenancy keeps the tenant on the model. */
    protected function fromSpatie(): string|int|null
    {
        $class = 'Spatie\\Multitenancy\\Models\\Tenant';

        if (! class_exists($class) || ! method_exists($class, 'current')) {
            return null;
        }

        $tenant = $class::current();

        if ($tenant === null) {
            return null;
        }

        $key = method_exists($tenant, 'getKey') ? $tenant->getKey() : ($tenant->id ?? null);

        return is_string($key) || is_int($key) ? $key : null;
    }

    /** Domain based tenancy with no package: fall back to the request host. */
    protected function fromHost(): ?string
    {
        if (data_get($this->config, 'tenancy.strategy') !== 'host') {
            return null;
        }

        if (! function_exists('request')) {
            return null;
        }

        $host = request()?->getHost();

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * The key ends up in file paths and cache keys, so anything that could
     * escape a directory or collide has to go.
     */
    public function sanitise(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $key) ?? $key;
        $safe = trim(preg_replace('/\.{2,}/', '.', $safe) ?? $safe, '.-');

        return $safe === '' ? 'tenant' : strtolower($safe);
    }
}
