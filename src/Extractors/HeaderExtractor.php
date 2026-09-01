<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;

/**
 * Adds the headers every request needs: the configured defaults, a content
 * type when the endpoint accepts a body, and authorization when the route is
 * behind auth middleware.
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
