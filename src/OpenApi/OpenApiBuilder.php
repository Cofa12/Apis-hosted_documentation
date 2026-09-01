<?php

namespace Cofa\ApiDocs\OpenApi;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Data\ResponseExample;
use Illuminate\Support\Str;

/**
 * Compiles the extracted endpoints into an OpenAPI document.
 *
 * The document is the package's single source of truth: the Blade UI, the
 * export command and any external tool all read the same spec.
 */
class OpenApiBuilder
{
    /** Headers OpenAPI says must not be declared as header parameters. */
    protected const RESERVED_HEADERS = ['accept', 'content-type', 'authorization'];

    /** @var array<string, array<string, mixed>> */
    protected array $components = [];

    /** @var array<string, int> */
    protected array $operationIds = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        protected array $config = [],
        protected ?SchemaFactory $schemas = null,
    ) {
        $version = (string) data_get($this->config, 'openapi.version', '3.1.0');
        $this->schemas ??= new SchemaFactory(nullableAsType: version_compare($version, '3.1.0', '>='));
    }

    /**
     * @param  array<int, Endpoint>  $endpoints
     * @return array<string, mixed>
     */
    public function build(array $endpoints): array
    {
        $this->components = [];
        $this->operationIds = [];

        $document = [
            'openapi' => (string) data_get($this->config, 'openapi.version', '3.1.0'),
            'info' => $this->info(),
            'servers' => $this->servers(),
        ];

        $tags = $this->tags($endpoints);

        if ($tags !== []) {
            $document['tags'] = $tags;
        }

        $document['paths'] = $this->paths($endpoints);
        $document['components'] = $this->componentsSection();

        $extensions = (array) data_get($this->config, 'openapi.extensions', []);

        return $extensions === [] ? $document : array_replace_recursive($document, $extensions);
    }

    /** @return array<string, mixed> */
    protected function info(): array
    {
        $info = array_filter([
            'title' => (string) data_get($this->config, 'title', 'API Documentation'),
            'version' => (string) data_get($this->config, 'version', '1.0.0'),
            'description' => (string) data_get($this->config, 'description', ''),
            'termsOfService' => data_get($this->config, 'openapi.terms_of_service'),
        ], fn ($value) => $value !== null && $value !== '');

        $contact = array_filter((array) data_get($this->config, 'openapi.contact', []));
        $license = array_filter((array) data_get($this->config, 'openapi.license', []));

        if ($contact !== []) {
            $info['contact'] = $contact;
        }

        if ($license !== []) {
            $info['license'] = $license;
        }

        return $info;
    }

    /** @return array<int, array<string, mixed>> */
    protected function servers(): array
    {
        $servers = (array) data_get($this->config, 'openapi.servers', []);

        if ($servers !== []) {
            return array_values($servers);
        }

        return [[
            'url' => rtrim((string) data_get($this->config, 'base_url', ''), '/'),
            'description' => 'Default server',
        ]];
    }

    /**
     * @param  array<int, Endpoint>  $endpoints
     * @return array<int, array<string, mixed>>
     */
    protected function tags(array $endpoints): array
    {
        $tags = [];

        foreach ($endpoints as $endpoint) {
            $name = $endpoint->group;

            if (! isset($tags[$name])) {
                $tags[$name] = ['name' => $name];
            }

            $description = $endpoint->meta['group_description'] ?? null;

            if (is_string($description) && $description !== '' && ! isset($tags[$name]['description'])) {
                $tags[$name]['description'] = $description;
            }
        }

        $order = (array) data_get($this->config, 'grouping.order', []);

        uksort($tags, function (string $a, string $b) use ($order) {
            $ai = array_search($a, $order, true);
            $bi = array_search($b, $order, true);
            $ai = $ai === false ? PHP_INT_MAX : $ai;
            $bi = $bi === false ? PHP_INT_MAX : $bi;

            return $ai === $bi ? strcasecmp($a, $b) : $ai <=> $bi;
        });

        return array_values($tags);
    }

    /**
     * @param  array<int, Endpoint>  $endpoints
     * @return array<string, mixed>
     */
    protected function paths(array $endpoints): array
    {
        $paths = [];

        foreach ($endpoints as $endpoint) {
            $path = $this->pathFor($endpoint);

            foreach ($endpoint->methods as $method) {
                $verb = strtolower($method);

                if (in_array($verb, ['head', 'options'], true)) {
                    continue;
                }

                $paths[$path][$verb] = $this->operation($endpoint, $method);
            }
        }

        ksort($paths);

        return $paths;
    }

    public function pathFor(Endpoint $endpoint): string
    {
        // OpenAPI has no notion of an optional path segment.
        $uri = str_replace('?}', '}', $endpoint->uri);

        return '/' . ltrim($uri, '/');
    }

    /** @return array<string, mixed> */
    protected function operation(Endpoint $endpoint, string $method): array
    {
        $operation = [
            'tags' => array_values(array_unique(array_merge([$endpoint->group], (array) ($endpoint->meta['tags'] ?? [])))),
            'summary' => $endpoint->displayTitle(),
            'operationId' => $this->operationId($endpoint, $method),
        ];

        if ($endpoint->description !== '') {
            $operation['description'] = $endpoint->description;
        }

        if ($endpoint->deprecated) {
            $operation['deprecated'] = true;

            if ($endpoint->deprecationNote !== null) {
                $operation['description'] = trim(($operation['description'] ?? '') . "\n\n**Deprecated:** " . $endpoint->deprecationNote);
            }
        }

        $parameters = $this->parameters($endpoint);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $requestBody = $this->requestBody($endpoint, $method);

        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $operation['responses'] = $this->responses($endpoint);
        $operation['security'] = $this->security($endpoint);

        // Lossless extras for the renderer: reserved headers, handler, middleware.
        $headers = array_map(fn (HeaderParam $header) => $header->toArray(), $endpoint->headers);

        if ($headers !== []) {
            $operation['x-headers'] = $headers;
        }

        if ($endpoint->handler() !== null) {
            $operation['x-controller'] = $endpoint->handler();
        }

        if ($endpoint->middleware !== []) {
            $operation['x-middleware'] = $endpoint->middleware;
        }

        if ($endpoint->name !== null) {
            $operation['x-route-name'] = $endpoint->name;
        }

        if (isset($endpoint->meta['paginated'])) {
            $operation['x-paginated'] = $endpoint->meta['paginated'];
        }

        return $operation;
    }

    public function operationId(Endpoint $endpoint, string $method): string
    {
        $explicit = $endpoint->meta['operation_id'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $slug = str_replace(['{', '}', '?', '/', '-', '.'], [' ', ' ', '', ' ', ' ', ' '], $endpoint->uri);
        $id = Str::camel(strtolower($method) . ' ' . $slug);

        if (isset($this->operationIds[$id])) {
            $this->operationIds[$id]++;

            return $id . $this->operationIds[$id];
        }

        $this->operationIds[$id] = 1;

        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    protected function parameters(Endpoint $endpoint): array
    {
        $parameters = [];

        foreach ($endpoint->urlParameters as $parameter) {
            $parameters[] = $this->parameterObject($parameter, 'path', forceRequired: true);
        }

        foreach ($endpoint->queryParameters as $parameter) {
            $parameters[] = $this->parameterObject($parameter, 'query');
        }

        foreach ($endpoint->headers as $header) {
            if (in_array(strtolower($header->name), self::RESERVED_HEADERS, true)) {
                continue;
            }

            $parameters[] = array_filter([
                'name' => $header->name,
                'in' => 'header',
                'required' => $header->required,
                'description' => $header->description,
                'schema' => array_filter([
                    'type' => 'string',
                    'examples' => $header->value === '' ? null : [$header->value],
                ]),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $parameters;
    }

    /** @return array<string, mixed> */
    protected function parameterObject(Parameter $parameter, string $in, bool $forceRequired = false): array
    {
        $schema = $this->schemas->fromParameter($parameter);
        $description = $parameter->description;

        // The schema description would duplicate the parameter description.
        unset($schema['description']);

        $object = [
            'name' => $parameter->name,
            'in' => $in,
            'required' => $forceRequired || $parameter->required,
        ];

        if ($description !== '') {
            $object['description'] = $description;
        }

        $object['schema'] = $schema;

        if ($parameter->example !== null) {
            $object['example'] = $parameter->example;
        }

        if ($in === 'query' && ($parameter->type === 'array' || str_ends_with($parameter->type, '[]'))) {
            $object['style'] = 'form';
            $object['explode'] = true;
        }

        return $object;
    }

    /** @return array<string, mixed>|null */
    protected function requestBody(Endpoint $endpoint, string $method): ?array
    {
        if ($endpoint->bodyParameters === [] || in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        $schema = $this->schemas->objectFromParameters($endpoint->bodyParameters);
        $mediaType = ! empty($endpoint->meta['multipart']) ? 'multipart/form-data' : 'application/json';

        $required = false;

        foreach ($endpoint->bodyParameters as $parameter) {
            if ($parameter->required) {
                $required = true;

                break;
            }
        }

        $media = ['schema' => $schema];
        $example = $this->exampleFromParameters($endpoint->bodyParameters);

        if ($example !== []) {
            $media['example'] = $example;
        }

        return [
            'required' => $required,
            'content' => [$mediaType => $media],
        ];
    }

    /**
     * @param  array<int, Parameter>  $parameters
     * @return array<string, mixed>
     */
    public function exampleFromParameters(array $parameters): array
    {
        $example = [];

        foreach ($parameters as $parameter) {
            if ($parameter->children !== []) {
                $nested = $this->exampleFromParameters($parameter->children);
                $example[$parameter->name] = str_ends_with($parameter->type, '[]') ? [$nested] : $nested;

                continue;
            }

            if ($parameter->example === null) {
                continue;
            }

            $example[$parameter->name] = $parameter->example;
        }

        return $example;
    }

    /** @return array<string, mixed> */
    protected function responses(Endpoint $endpoint): array
    {
        $responses = [];

        foreach ($endpoint->responses as $response) {
            $responses[(string) $response->status] = $this->responseObject($response);
        }

        if ($responses === []) {
            $responses['200'] = ['description' => 'OK'];
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    protected function responseObject(ResponseExample $response): array
    {
        $object = [
            'description' => $response->description !== '' ? $response->description : $response->statusText(),
        ];

        if ($response->headers !== []) {
            foreach ($response->headers as $name => $value) {
                $object['headers'][(string) $name] = [
                    'schema' => ['type' => 'string', 'examples' => [$value]],
                ];
            }
        }

        if ($response->status === 204 || $response->content === null) {
            return $object;
        }

        $schema = $this->responseSchema($response);
        $media = ['schema' => $schema];

        if (! is_string($response->content)) {
            $media['example'] = $response->content;
        }

        $object['content'] = [$response->contentType => $media];

        return $object;
    }

    /** @return array<string, mixed> */
    protected function responseSchema(ResponseExample $response): array
    {
        $useComponents = (bool) data_get($this->config, 'openapi.use_components', true);
        $wrapper = data_get($this->config, 'responses.resource_wrapper', 'data');

        if (! $useComponents || $response->schemaName === null || ! is_array($response->content)) {
            return $this->schemas->fromExample($response->content);
        }

        $name = $this->componentName($response->schemaName);
        $content = $response->content;
        $hasWrapper = is_string($wrapper) && $wrapper !== '' && array_key_exists($wrapper, $content);
        $payload = $hasWrapper ? $content[$wrapper] : $content;

        $item = $response->collection
            ? (is_array($payload) && $payload !== [] ? reset($payload) : null)
            : $payload;

        if (! is_array($item)) {
            return $this->schemas->fromExample($response->content);
        }

        $this->components['schemas'][$name] ??= $this->schemas->fromExample($item);

        $reference = ['$ref' => '#/components/schemas/' . $name];
        $itemSchema = $response->collection ? ['type' => 'array', 'items' => $reference] : $reference;

        if (! $hasWrapper) {
            return $itemSchema;
        }

        $properties = [$wrapper => $itemSchema];

        // Keep the pagination envelope (links/meta) alongside the reference.
        foreach ($content as $key => $value) {
            if ($key === $wrapper) {
                continue;
            }

            $properties[(string) $key] = $this->schemas->fromExample($value);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    public function componentName(string $name): string
    {
        return Str::studly(preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? $name);
    }

    /** @return array<int, array<string, array<int, string>>> */
    protected function security(Endpoint $endpoint): array
    {
        if (! $endpoint->authenticated) {
            return [];
        }

        $scheme = $endpoint->meta['security_scheme']
            ?? data_get($this->config, 'openapi.default_security_scheme', 'bearerAuth');

        return [[(string) $scheme => []]];
    }

    /** @return array<string, mixed> */
    protected function componentsSection(): array
    {
        $components = $this->components;

        $schemes = (array) data_get($this->config, 'openapi.security_schemes', []);

        if ($schemes !== []) {
            $components['securitySchemes'] = $schemes;
        }

        // Shared error shapes keep the document small and consistent.
        $components['schemas']['ErrorResponse'] ??= [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'examples' => ['Server Error']]],
            'required' => ['message'],
        ];

        $components['schemas']['ValidationErrorResponse'] ??= [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'examples' => ['The given data was invalid.']],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'required' => ['message', 'errors'],
        ];

        if (isset($components['schemas'])) {
            ksort($components['schemas']);
        }

        return $components;
    }
}
