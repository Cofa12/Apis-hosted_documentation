<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Attributes\ApiDoc;
use Cofa\ApiDocs\Attributes\ApiGroup;
use Cofa\ApiDocs\Attributes\ApiHeader;
use Cofa\ApiDocs\Attributes\ApiParam;
use Cofa\ApiDocs\Attributes\ApiResponse;
use Cofa\ApiDocs\Attributes\Authenticated;
use Cofa\ApiDocs\Attributes\Unauthenticated;
use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Data\ResponseExample;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\ExampleFactory;
use Cofa\ApiDocs\Support\ParameterTree;

/**
 * The typed, IDE friendly counterpart to the docblock tags.
 */
class AttributeExtractor implements Extractor
{
    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $this->applyGroup($endpoint, $context);
        $this->applyDoc($endpoint, $context);
        $this->applyAuth($endpoint, $context);
        $this->applyParameters($endpoint, $context);
        $this->applyHeaders($endpoint, $context);
        $this->applyResponses($endpoint, $context);
    }

    protected function applyGroup(Endpoint $endpoint, RouteContext $context): void
    {
        $group = $context->attribute(ApiGroup::class);

        if ($group instanceof ApiGroup && $group->name !== '') {
            $endpoint->group = $group->name;

            if ($group->description !== '') {
                $endpoint->meta['group_description'] = $group->description;
            }
        }
    }

    protected function applyDoc(Endpoint $endpoint, RouteContext $context): void
    {
        $doc = $context->attribute(ApiDoc::class, includeClass: false);

        if (! $doc instanceof ApiDoc) {
            return;
        }

        if ($doc->summary !== '') {
            $endpoint->title = $doc->summary;
        }

        if ($doc->description !== '') {
            $endpoint->description = $doc->description;
        }

        if ($doc->operationId !== null) {
            $endpoint->meta['operation_id'] = $doc->operationId;
        }

        if ($doc->deprecated) {
            $endpoint->deprecated = true;
        }

        foreach ($doc->tags as $tag) {
            $endpoint->meta['tags'][] = $tag;
        }
    }

    protected function applyAuth(Endpoint $endpoint, RouteContext $context): void
    {
        $authenticated = $context->attribute(Authenticated::class);

        if ($authenticated instanceof Authenticated) {
            $endpoint->authenticated = true;

            if ($authenticated->scheme !== null) {
                $endpoint->meta['security_scheme'] = $authenticated->scheme;
            }
        }

        if ($context->attribute(Unauthenticated::class) !== null) {
            $endpoint->authenticated = false;
        }
    }

    protected function applyParameters(Endpoint $endpoint, RouteContext $context): void
    {
        $buckets = ['body' => [], 'query' => [], 'path' => []];

        foreach ($context->attributes(ApiParam::class, includeClass: false) as $attribute) {
            $in = strtolower($attribute->in);
            $in = match ($in) {
                'url', 'path' => 'path',
                'query' => 'query',
                default => 'body',
            };

            $parameter = new Parameter(
                name: $attribute->name,
                type: $attribute->type,
                required: $attribute->required,
                description: $attribute->description,
                example: $attribute->example ?? ExampleFactory::forParameter(
                    $attribute->name,
                    $attribute->type,
                    [],
                    $attribute->enum
                ),
                enum: $attribute->enum,
            );

            $buckets[$in][] = $parameter;
        }

        $map = [
            'body' => 'bodyParameters',
            'query' => 'queryParameters',
            'path' => 'urlParameters',
        ];

        foreach ($buckets as $in => $parameters) {
            if ($parameters === []) {
                continue;
            }

            $endpoint->mergeParameters(
                $map[$in],
                $in === 'path' ? $parameters : ParameterTree::nest($parameters),
                preferNew: true
            );
        }
    }

    protected function applyHeaders(Endpoint $endpoint, RouteContext $context): void
    {
        foreach ($context->attributes(ApiHeader::class) as $attribute) {
            $endpoint->addHeader(new HeaderParam(
                name: $attribute->name,
                value: $attribute->value,
                required: $attribute->required,
                description: $attribute->description,
            ));
        }
    }

    protected function applyResponses(Endpoint $endpoint, RouteContext $context): void
    {
        foreach ($context->attributes(ApiResponse::class, includeClass: false) as $attribute) {
            if ($attribute->resource !== null) {
                $endpoint->meta['resource_responses'][] = [
                    'status' => $attribute->status,
                    'class' => ltrim($attribute->resource, '\\'),
                    'collection' => $attribute->collection,
                    'description' => $attribute->description,
                ];

                continue;
            }

            $endpoint->addResponse(new ResponseExample(
                status: $attribute->status,
                content: $attribute->content,
                description: $attribute->description,
                contentType: $attribute->contentType,
            ));
        }
    }
}
