<?php

namespace Cofa\ApiDocs\OpenApi;

use Illuminate\Support\Str;

/**
 * One path + method pair of an OpenAPI document, presented the way the
 * documentation UI wants to read it.
 */
class Operation
{
    /** @param array<string, mixed> $operation */
    public function __construct(
        protected Spec $spec,
        public string $path,
        public string $method,
        protected array $operation = [],
    ) {
        $this->method = strtoupper($this->method);
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->operation;
    }

    public function id(): string
    {
        return Str::slug($this->method . '-' . str_replace(['{', '}', '/'], ['', '', '-'], $this->path)) ?: 'operation';
    }

    public function operationId(): string
    {
        $id = $this->operation['operationId'] ?? null;

        return is_string($id) ? $id : $this->id();
    }

    public function summary(): string
    {
        $summary = $this->operation['summary'] ?? '';

        return is_string($summary) && $summary !== '' ? $summary : $this->method . ' ' . $this->path;
    }

    public function description(): string
    {
        $description = $this->operation['description'] ?? '';

        return is_string($description) ? $description : '';
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        $tags = $this->operation['tags'] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }

    public function group(): string
    {
        return $this->tags()[0] ?? 'General';
    }

    public function isDeprecated(): bool
    {
        return (bool) ($this->operation['deprecated'] ?? false);
    }

    public function hasBody(): bool
    {
        return $this->requestSchema() !== null;
    }

    public function isAuthenticated(): bool
    {
        $security = $this->operation['security'] ?? null;

        return is_array($security) && $security !== [];
    }

    /** @return array<int, string> */
    public function securitySchemes(): array
    {
        $security = $this->operation['security'] ?? [];
        $names = [];

        if (! is_array($security)) {
            return [];
        }

        foreach ($security as $requirement) {
            if (is_array($requirement)) {
                foreach (array_keys($requirement) as $name) {
                    $names[] = (string) $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parameters(?string $in = null): array
    {
        $parameters = array_merge(
            $this->spec->pathLevelParameters($this->path),
            is_array($this->operation['parameters'] ?? null) ? $this->operation['parameters'] : []
        );

        $result = [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            if (isset($parameter['$ref']) && is_string($parameter['$ref'])) {
                $resolved = $this->spec->resolveRef($parameter['$ref']);
                $parameter = is_array($resolved) ? $resolved : $parameter;
            }

            if ($in !== null && ($parameter['in'] ?? null) !== $in) {
                continue;
            }

            $parameter['schema_object'] = SchemaObject::make(
                is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [],
                $this->spec,
                (string) ($parameter['name'] ?? ''),
                (bool) ($parameter['required'] ?? false),
            );

            $result[] = $parameter;
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function pathParameters(): array
    {
        return $this->parameters('path');
    }

    /** @return array<int, array<string, mixed>> */
    public function queryParameters(): array
    {
        return $this->parameters('query');
    }

    /**
     * Every request header worth showing: the ones OpenAPI reserves are kept
     * in the x-headers extension so nothing is lost.
     *
     * @return array<int, array{name: string, value: string, required: bool, description: string}>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ($this->operation['x-headers'] ?? [] as $header) {
            if (! is_array($header) || ! isset($header['name'])) {
                continue;
            }

            $headers[strtolower((string) $header['name'])] = [
                'name' => (string) $header['name'],
                'value' => (string) ($header['value'] ?? ''),
                'required' => (bool) ($header['required'] ?? false),
                'description' => (string) ($header['description'] ?? ''),
            ];
        }

        foreach ($this->parameters('header') as $parameter) {
            $name = (string) ($parameter['name'] ?? '');

            if ($name === '' || isset($headers[strtolower($name)])) {
                continue;
            }

            /** @var SchemaObject $schema */
            $schema = $parameter['schema_object'];
            $example = $schema->example();

            $headers[strtolower($name)] = [
                'name' => $name,
                'value' => is_scalar($example) ? (string) $example : '',
                'required' => (bool) ($parameter['required'] ?? false),
                'description' => (string) ($parameter['description'] ?? ''),
            ];
        }

        return array_values($headers);
    }

    public function requestMediaType(): ?string
    {
        $content = $this->operation['requestBody']['content'] ?? null;

        if (! is_array($content) || $content === []) {
            return null;
        }

        foreach (array_keys($content) as $type) {
            return (string) $type;
        }

        return null;
    }

    public function requestSchema(): ?SchemaObject
    {
        $mediaType = $this->requestMediaType();

        if ($mediaType === null) {
            return null;
        }

        $schema = $this->operation['requestBody']['content'][$mediaType]['schema'] ?? null;

        return is_array($schema) ? SchemaObject::make($schema, $this->spec) : null;
    }

    public function requestBodyRequired(): bool
    {
        return (bool) ($this->operation['requestBody']['required'] ?? false);
    }

    public function requestExample(): mixed
    {
        $mediaType = $this->requestMediaType();

        if ($mediaType === null) {
            return null;
        }

        $media = $this->operation['requestBody']['content'][$mediaType] ?? [];

        if (is_array($media) && array_key_exists('example', $media)) {
            return $media['example'];
        }

        return $this->requestSchema()?->example();
    }

    public function requestExampleJson(): string
    {
        $example = $this->requestExample();

        return $example === null
            ? ''
            : (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<int, ResponseView> */
    public function responses(): array
    {
        $responses = $this->operation['responses'] ?? [];

        if (! is_array($responses)) {
            return [];
        }

        $views = [];

        foreach ($responses as $status => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (isset($definition['$ref']) && is_string($definition['$ref'])) {
                $resolved = $this->spec->resolveRef($definition['$ref']);
                $definition = is_array($resolved) ? $resolved : $definition;
            }

            $views[] = new ResponseView($this->spec, (string) $status, $definition);
        }

        usort($views, fn (ResponseView $a, ResponseView $b) => $a->sortKey() <=> $b->sortKey());

        return $views;
    }

    /** @return array<int, ResponseView> */
    public function successResponses(): array
    {
        return array_values(array_filter($this->responses(), fn (ResponseView $r) => $r->isSuccessful()));
    }

    /** @return array<int, ResponseView> */
    public function errorResponses(): array
    {
        return array_values(array_filter($this->responses(), fn (ResponseView $r) => ! $r->isSuccessful()));
    }

    public function controller(): ?string
    {
        $value = $this->operation['x-controller'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<int, string> */
    public function middleware(): array
    {
        $value = $this->operation['x-middleware'] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function routeName(): ?string
    {
        $value = $this->operation['x-route-name'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function url(?string $baseUrl = null): string
    {
        return rtrim($baseUrl ?? $this->spec->baseUrl(), '/') . $this->path;
    }

    /** The same URL but with example values in place of the placeholders. */
    public function resolvedUrl(?string $baseUrl = null): string
    {
        return rtrim($baseUrl ?? $this->spec->baseUrl(), '/') . $this->resolvedPath();
    }

    /** The path with example values substituted, ready for a real request. */
    public function resolvedPath(): string
    {
        $path = $this->path;

        foreach ($this->pathParameters() as $parameter) {
            $name = (string) ($parameter['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $example = $parameter['example'] ?? null;

            if ($example === null && isset($parameter['schema_object'])) {
                /** @var SchemaObject $schema */
                $schema = $parameter['schema_object'];
                $example = $schema->example();
            }

            $value = is_scalar($example) ? (string) $example : $name;
            $path = str_replace(['{' . $name . '}', '{' . $name . '?}'], rawurlencode($value), $path);
        }

        return $path;
    }
}
