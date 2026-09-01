<?php

namespace Cofa\ApiDocs\OpenApi;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * An OpenAPI document, wrapped in the accessors the renderer needs.
 *
 * The spec can come from this application's routes or from any external
 * OpenAPI 3.x file – the UI does not care which.
 */
class Spec
{
    /** @param array<string, mixed> $document */
    public function __construct(protected array $document = [])
    {
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        return new self($document);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The OpenAPI document is not valid JSON.');
        }

        return new self($decoded);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("OpenAPI document not found at [{$path}].");
        }

        $contents = (string) file_get_contents($path);

        if (Str::endsWith(strtolower($path), ['.yaml', '.yml'])) {
            if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                throw new RuntimeException('Reading YAML specs requires symfony/yaml.');
            }

            $parsed = \Symfony\Component\Yaml\Yaml::parse($contents);

            if (! is_array($parsed)) {
                throw new RuntimeException("The OpenAPI document at [{$path}] is not valid YAML.");
            }

            return new self($parsed);
        }

        return self::fromJson($contents);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode($this->document, $flags);
    }

    public function toYaml(): string
    {
        if (! class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            throw new RuntimeException('Writing YAML specs requires symfony/yaml.');
        }

        return \Symfony\Component\Yaml\Yaml::dump($this->document, 12, 2, \Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP);
    }

    public function openapiVersion(): string
    {
        return (string) ($this->document['openapi'] ?? '3.1.0');
    }

    public function title(): string
    {
        return (string) ($this->document['info']['title'] ?? 'API Documentation');
    }

    public function version(): string
    {
        return (string) ($this->document['info']['version'] ?? '1.0.0');
    }

    public function description(): string
    {
        return (string) ($this->document['info']['description'] ?? '');
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        $info = $this->document['info'] ?? [];

        return is_array($info) ? $info : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function servers(): array
    {
        $servers = $this->document['servers'] ?? [];

        return is_array($servers) ? array_values(array_filter($servers, 'is_array')) : [];
    }

    public function baseUrl(): string
    {
        $url = $this->servers()[0]['url'] ?? '';

        return rtrim(is_string($url) ? $url : '', '/');
    }

    /** @return array<int, array<string, mixed>> */
    public function tags(): array
    {
        $tags = $this->document['tags'] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, 'is_array')) : [];
    }

    public function tagDescription(string $name): string
    {
        foreach ($this->tags() as $tag) {
            if (($tag['name'] ?? null) === $name) {
                return (string) ($tag['description'] ?? '');
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    public function paths(): array
    {
        $paths = $this->document['paths'] ?? [];

        return is_array($paths) ? $paths : [];
    }

    /**
     * Parameters declared once for a whole path item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pathLevelParameters(string $path): array
    {
        $parameters = $this->paths()[$path]['parameters'] ?? [];

        return is_array($parameters) ? array_values(array_filter($parameters, 'is_array')) : [];
    }

    /** @return array<int, Operation> */
    public function operations(): array
    {
        $verbs = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
        $operations = [];

        foreach ($this->paths() as $path => $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($verbs as $verb) {
                if (! isset($item[$verb]) || ! is_array($item[$verb])) {
                    continue;
                }

                $operations[] = new Operation($this, (string) $path, $verb, $item[$verb]);
            }
        }

        return $operations;
    }

    /**
     * Operations bucketed by their first tag, in the document's tag order.
     *
     * @return array<string, array<int, Operation>>
     */
    public function groupedOperations(): array
    {
        $groups = [];

        foreach ($this->tags() as $tag) {
            $name = (string) ($tag['name'] ?? '');

            if ($name !== '') {
                $groups[$name] = [];
            }
        }

        foreach ($this->operations() as $operation) {
            $groups[$operation->group()][] = $operation;
        }

        return array_filter($groups, fn (array $operations) => $operations !== []);
    }

    public function operationCount(): int
    {
        return count($this->operations());
    }

    public function isEmpty(): bool
    {
        return $this->operationCount() === 0;
    }

    /** @return array<string, mixed> */
    public function securitySchemes(): array
    {
        $schemes = $this->document['components']['securitySchemes'] ?? [];

        return is_array($schemes) ? $schemes : [];
    }

    /** @return array<string, mixed> */
    public function componentSchemas(): array
    {
        $schemas = $this->document['components']['schemas'] ?? [];

        return is_array($schemas) ? $schemas : [];
    }

    /**
     * Resolve an internal `$ref` pointer.
     *
     * @return array<string, mixed>|null
     */
    public function resolveRef(string $ref): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $segments = array_map(
            fn (string $segment) => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($ref, 2))
        );

        $cursor = $this->document;

        foreach ($segments as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return is_array($cursor) ? $cursor : null;
    }
}
