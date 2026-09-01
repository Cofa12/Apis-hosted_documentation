<?php

namespace Cofa\ApiDocs;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Extractors\AttributeExtractor;
use Cofa\ApiDocs\Extractors\DocBlockExtractor;
use Cofa\ApiDocs\Extractors\ErrorResponseExtractor;
use Cofa\ApiDocs\Extractors\HeaderExtractor;
use Cofa\ApiDocs\Extractors\QueryParameterExtractor;
use Cofa\ApiDocs\Extractors\ResponseExtractor;
use Cofa\ApiDocs\Extractors\RouteMetadataExtractor;
use Cofa\ApiDocs\Extractors\UrlParameterExtractor;
use Cofa\ApiDocs\Extractors\ValidationExtractor;
use Cofa\ApiDocs\OpenApi\OpenApiBuilder;
use Cofa\ApiDocs\OpenApi\Spec;
use Cofa\ApiDocs\Scanning\RouteScanner;
use Cofa\ApiDocs\Support\AstResolver;
use Cofa\ApiDocs\Support\ModelSchemaInspector;
use Cofa\ApiDocs\Support\ResourceSchemaInspector;
use Cofa\ApiDocs\Support\ValidationRuleParser;
use Cofa\ApiDocs\Tenancy\Tenancy;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Throwable;

/**
 * The entry point: scans the application and hands back an OpenAPI document.
 */
class DocumentationGenerator
{
    protected ?RouteScanner $scanner = null;

    protected Tenancy $tenancy;

    /** Why the cache could not be used, if it could not be used. */
    protected ?string $cacheError = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        protected Router $router,
        protected array $config = [],
        protected ?CacheRepository $cache = null,
        ?Tenancy $tenancy = null,
    ) {
        $this->tenancy = $tenancy ?? new Tenancy($config);
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        $this->tenancy->setConfig($config);
        $this->scanner = null;

        return $this;
    }

    /**
     * The configuration with the {tenant} placeholder resolved.
     *
     * It is resolved on every read rather than once, because a console command
     * may walk through several tenants in a single process.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->tenancy->applyToConfig($this->config);
    }

    public function tenancy(): Tenancy
    {
        return $this->tenancy;
    }

    public function scanner(): RouteScanner
    {
        if ($this->scanner !== null) {
            return $this->scanner;
        }

        $ast = new AstResolver();
        $models = new ModelSchemaInspector();
        $resources = new ResourceSchemaInspector(
            $ast,
            $models,
            (int) data_get($this->config, 'responses.max_depth', 4)
        );

        return $this->scanner = new RouteScanner($this->router, $this->config, [
            new RouteMetadataExtractor($this->config),
            new UrlParameterExtractor(),
            new ValidationExtractor($ast, new ValidationRuleParser(), $this->config),
            new QueryParameterExtractor($ast),
            new DocBlockExtractor(),
            new AttributeExtractor(),
            new HeaderExtractor($this->config),
            new ResponseExtractor($ast, $resources, $models, $this->config),
            new ErrorResponseExtractor($this->config),
        ]);
    }

    /** @return array<int, Endpoint> */
    public function endpoints(): array
    {
        return $this->scanner()->scan();
    }

    /** Build the OpenAPI document from scratch. */
    public function generate(): Spec
    {
        $source = data_get($this->config, 'openapi.source', 'routes');

        if (is_string($source) && $source !== 'routes' && $source !== '') {
            return $this->loadExternal($source);
        }

        $builder = new OpenApiBuilder($this->config());

        return Spec::fromArray($builder->build($this->endpoints()));
    }

    /** The document used for rendering, honouring the cache configuration. */
    public function spec(bool $fresh = false): Spec
    {
        $enabled = (bool) data_get($this->config, 'cache.enabled', false);

        if ($fresh || ! $enabled || $this->cache === null) {
            return $this->generate();
        }

        $ttl = (int) data_get($this->config, 'cache.ttl', 3600);

        try {
            $document = $this->cache->remember($this->cacheKey(), $ttl, fn () => $this->generate()->toArray());
        } catch (Throwable $exception) {
            // An unreachable cache store - a missing table, a down Redis - is
            // no reason to stop serving documentation. Scan live instead.
            $this->cacheError = $exception->getMessage();

            return $this->generate();
        }

        return Spec::fromArray(is_array($document) ? $document : []);
    }

    public function cacheKey(): string
    {
        return $this->tenancy->cacheKey((string) data_get($this->config, 'cache.key', 'api-docs.documentation'));
    }

    public function cacheEnabled(): bool
    {
        return (bool) data_get($this->config, 'cache.enabled', false);
    }

    /** The reason the cache was skipped, if it was skipped. */
    public function cacheError(): ?string
    {
        return $this->cacheError;
    }

    /**
     * Drop the cached document.
     *
     * With caching switched off nothing was ever written, so the store is left
     * untouched: reaching for it would make an unrelated, possibly unmigrated
     * cache backend fail a command that has already done its job. Pass $force
     * when the user explicitly asked for the cache to be cleared.
     */
    public function forgetCache(bool $force = false): bool
    {
        if ($this->cache === null || (! $force && ! $this->cacheEnabled())) {
            return false;
        }

        try {
            $this->cache->forget($this->cacheKey());

            return true;
        } catch (Throwable $exception) {
            $this->cacheError = $exception->getMessage();

            return false;
        }
    }

    /** @return array<int, array{route: string, error: string}> */
    public function errors(): array
    {
        return $this->scanner()->errors();
    }

    protected function loadExternal(string $source): Spec
    {
        if (Str::startsWith($source, ['http://', 'https://'])) {
            $contents = @file_get_contents($source);

            if ($contents === false) {
                throw new \RuntimeException("Unable to download the OpenAPI document from [{$source}].");
            }

            return Str::endsWith(strtolower($source), ['.yaml', '.yml'])
                ? Spec::fromArray((array) \Symfony\Component\Yaml\Yaml::parse($contents))
                : Spec::fromJson($contents);
        }

        $path = Str::startsWith($source, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $source) === 1
            ? $source
            : (function_exists('base_path') ? base_path($source) : $source);

        return Spec::fromFile($path);
    }
}
