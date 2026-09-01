<?php

namespace Cofa\ApiDocs\Data;

use Illuminate\Support\Str;
use JsonSerializable;

/**
 * Everything that is known about one documented endpoint.
 */
class Endpoint implements JsonSerializable
{
    /** @var array<int, string> */
    public array $methods = [];

    public string $uri = '';

    public ?string $name = null;

    public string $group = 'General';

    public ?string $subgroup = null;

    public string $title = '';

    public string $description = '';

    public ?string $controller = null;

    public ?string $action = null;

    /** @var array<int, string> */
    public array $middleware = [];

    public bool $authenticated = false;

    public bool $deprecated = false;

    public ?string $deprecationNote = null;

    /** @var array<int, Parameter> */
    public array $urlParameters = [];

    /** @var array<int, Parameter> */
    public array $queryParameters = [];

    /** @var array<int, Parameter> */
    public array $bodyParameters = [];

    /** @var array<int, HeaderParam> */
    public array $headers = [];

    /** @var array<int, ResponseExample> */
    public array $responses = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public int $order = 0;

    public function __construct(array $methods = [], string $uri = '')
    {
        $this->methods = $methods;
        $this->uri = $uri;
    }

    /** The verb shown on the badge – the most specific one of the route. */
    public function method(): string
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'GET'] as $method) {
            if (in_array($method, $this->methods, true)) {
                return $method;
            }
        }

        return $this->methods[0] ?? 'GET';
    }

    public function path(): string
    {
        return '/' . ltrim($this->uri, '/');
    }

    public function hasBody(): bool
    {
        return (bool) array_intersect($this->methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /** Stable DOM/anchor identifier for this endpoint. */
    public function id(): string
    {
        return Str::slug($this->method() . '-' . str_replace(['{', '}', '/'], ['', '', '-'], $this->uri)) ?: 'endpoint';
    }

    public function displayTitle(): string
    {
        return $this->title !== '' ? $this->title : $this->method() . ' ' . $this->path();
    }

    public function handler(): ?string
    {
        if ($this->controller === null) {
            return null;
        }

        return $this->action === null ? $this->controller : $this->controller . '@' . $this->action;
    }

    /** @return array<int, ResponseExample> */
    public function successResponses(): array
    {
        return array_values(array_filter($this->responses, fn (ResponseExample $r) => $r->isSuccessful()));
    }

    /** @return array<int, ResponseExample> */
    public function errorResponses(): array
    {
        return array_values(array_filter($this->responses, fn (ResponseExample $r) => ! $r->isSuccessful()));
    }

    public function addResponse(ResponseExample $response, bool $overwrite = true): self
    {
        foreach ($this->responses as $index => $existing) {
            if ($existing->status === $response->status) {
                if ($overwrite) {
                    $this->responses[$index] = $response;
                }

                return $this;
            }
        }

        $this->responses[] = $response;

        return $this;
    }

    public function addHeader(HeaderParam $header): self
    {
        foreach ($this->headers as $index => $existing) {
            if (strcasecmp($existing->name, $header->name) === 0) {
                $this->headers[$index] = $header;

                return $this;
            }
        }

        $this->headers[] = $header;

        return $this;
    }

    /**
     * Merge parameters into one of the parameter buckets, keeping whatever was
     * already known and letting explicit documentation refine it.
     *
     * @param  array<int, Parameter>  $parameters
     */
    public function mergeParameters(string $bucket, array $parameters, bool $preferNew = false): self
    {
        $current = $this->{$bucket};

        foreach ($parameters as $parameter) {
            $matched = false;

            foreach ($current as $existing) {
                if ($existing->name === $parameter->name) {
                    $existing->mergeFrom($parameter, $preferNew);
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $current[] = $parameter;
            }
        }

        $this->{$bucket} = $current;

        return $this;
    }

    public function sortResponses(): self
    {
        usort($this->responses, fn (ResponseExample $a, ResponseExample $b) => $a->status <=> $b->status);

        return $this;
    }

    public function toArray(): array
    {
        return [
            'methods' => $this->methods,
            'uri' => $this->uri,
            'name' => $this->name,
            'group' => $this->group,
            'subgroup' => $this->subgroup,
            'title' => $this->title,
            'description' => $this->description,
            'controller' => $this->controller,
            'action' => $this->action,
            'middleware' => $this->middleware,
            'authenticated' => $this->authenticated,
            'deprecated' => $this->deprecated,
            'deprecation_note' => $this->deprecationNote,
            'url_parameters' => array_map(fn (Parameter $p) => $p->toArray(), $this->urlParameters),
            'query_parameters' => array_map(fn (Parameter $p) => $p->toArray(), $this->queryParameters),
            'body_parameters' => array_map(fn (Parameter $p) => $p->toArray(), $this->bodyParameters),
            'headers' => array_map(fn (HeaderParam $h) => $h->toArray(), $this->headers),
            'responses' => array_map(fn (ResponseExample $r) => $r->toArray(), $this->responses),
            'meta' => $this->meta,
            'order' => $this->order,
        ];
    }

    public static function fromArray(array $data): self
    {
        $endpoint = new self($data['methods'] ?? [], $data['uri'] ?? '');
        $endpoint->name = $data['name'] ?? null;
        $endpoint->group = $data['group'] ?? 'General';
        $endpoint->subgroup = $data['subgroup'] ?? null;
        $endpoint->title = $data['title'] ?? '';
        $endpoint->description = $data['description'] ?? '';
        $endpoint->controller = $data['controller'] ?? null;
        $endpoint->action = $data['action'] ?? null;
        $endpoint->middleware = $data['middleware'] ?? [];
        $endpoint->authenticated = (bool) ($data['authenticated'] ?? false);
        $endpoint->deprecated = (bool) ($data['deprecated'] ?? false);
        $endpoint->deprecationNote = $data['deprecation_note'] ?? null;
        $endpoint->urlParameters = array_map(fn (array $p) => Parameter::fromArray($p), $data['url_parameters'] ?? []);
        $endpoint->queryParameters = array_map(fn (array $p) => Parameter::fromArray($p), $data['query_parameters'] ?? []);
        $endpoint->bodyParameters = array_map(fn (array $p) => Parameter::fromArray($p), $data['body_parameters'] ?? []);
        $endpoint->headers = array_map(fn (array $h) => HeaderParam::fromArray($h), $data['headers'] ?? []);
        $endpoint->responses = array_map(fn (array $r) => ResponseExample::fromArray($r), $data['responses'] ?? []);
        $endpoint->meta = $data['meta'] ?? [];
        $endpoint->order = (int) ($data['order'] ?? 0);

        return $endpoint;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
