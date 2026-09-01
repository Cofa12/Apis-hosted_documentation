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
use Cofa\ApiDocs\Scanning\PrecedenceGuard;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\ExampleFactory;
use Cofa\ApiDocs\Support\ParameterTree;

/**
 * The typed, IDE friendly counterpart to the docblock tags.
 */
class AttributeExtractor implements Extractor
{
    /** What the attributes actually declared, for the precedence check. */
    protected array $declared = [];

    public function __construct(protected PrecedenceGuard $guard = new PrecedenceGuard())
    {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $this->declared = [];

        $this->applyGroup($endpoint, $context);
        $this->applyDoc($endpoint, $context);
        $this->applyAuth($endpoint, $context);
        $this->applyParameters($endpoint, $context);
        $this->applyHeaders($endpoint, $context);
        $this->applyResponses($endpoint, $context);

        $endpoint->meta['declared']['attribute'] = $this->declared;

        // Attributes win, so anything they overruled is reported rather than
        // quietly dropped.
        $conflicts = $this->guard->compare(
            $endpoint,
            $endpoint->meta['declared']['docblock'] ?? [],
            $this->declared,
        );

        if ($conflicts !== []) {
            $endpoint->meta['conflicts'] = array_map(fn ($conflict) => $conflict->toArray(), $conflicts);
        }
    }

    protected function applyGroup(Endpoint $endpoint, RouteContext $context): void
    {
        $group = $context->attribute(ApiGroup::class);

        if ($group instanceof ApiGroup && $group->name !== '') {
            $endpoint->group = $group->name;
            $this->declared['operation']['group'] = $group->name;

            if ($group->description !== '') {
                $endpoint->meta['group_description'] = $group->description;
            }
        }
    }

    protected function applyDoc(Endpoint $endpoint, RouteContext $context): void
    {
        $declaration = $context->attributeDeclarations(ApiDoc::class, includeClass: false)[0] ?? null;

        if ($declaration === null) {
            return;
        }

        /** @var ApiDoc $doc */
        $doc = $declaration['instance'];
        $declared = $declaration['declared'];

        if (in_array('summary', $declared, true) && $doc->summary !== '') {
            $endpoint->title = $doc->summary;
            $this->declared['operation']['summary'] = $doc->summary;
        }

        if (in_array('description', $declared, true) && $doc->description !== '') {
            $endpoint->description = $doc->description;
            $this->declared['operation']['description'] = $doc->description;
        }

        if ($doc->operationId !== null) {
            $endpoint->meta['operation_id'] = $doc->operationId;
            $this->declared['operation']['operationId'] = $doc->operationId;
        }

        if (in_array('deprecated', $declared, true)) {
            $endpoint->deprecated = $doc->deprecated;
            $this->declared['operation']['deprecated'] = $doc->deprecated;
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
            $this->declared['operation']['authenticated'] = true;

            if ($authenticated->scheme !== null) {
                $endpoint->meta['security_scheme'] = $authenticated->scheme;
            }
        }

        if ($context->attribute(Unauthenticated::class) !== null) {
            $endpoint->authenticated = false;
            $this->declared['operation']['authenticated'] = false;
        }
    }

    protected function applyParameters(Endpoint $endpoint, RouteContext $context): void
    {
        $buckets = ['body' => [], 'query' => [], 'path' => []];

        $map = [
            'body' => 'bodyParameters',
            'query' => 'queryParameters',
            'path' => 'urlParameters',
        ];

        foreach ($context->attributeDeclarations(ApiParam::class, includeClass: false) as $declaration) {
            /** @var ApiParam $attribute */
            $attribute = $declaration['instance'];

            $in = match (strtolower($attribute->in)) {
                'url', 'path' => 'path',
                'query' => 'query',
                default => 'body',
            };

            // "name" and "in" say which parameter this is, not what it holds.
            $declared = array_values(array_diff($declaration['declared'], ['name', 'in']));

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
                declared: $declared,
            );

            $this->declared[$map[$in]][$parameter->name] = $this->valuesOf($parameter);

            $buckets[$in][] = $parameter;
        }

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

    /**
     * The values a definition actually stated, keyed by field.
     *
     * @return array<string, mixed>
     */
    protected function valuesOf(Parameter $parameter): array
    {
        $values = [];

        foreach ($parameter->declared ?? [] as $field) {
            $values[$field] = match ($field) {
                'type' => $parameter->type,
                'required' => $parameter->required,
                'description' => $parameter->description,
                'example' => $parameter->example,
                'enum' => $parameter->enum,
                default => null,
            };
        }

        return $values;
    }

    protected function applyHeaders(Endpoint $endpoint, RouteContext $context): void
    {
        foreach ($context->attributeDeclarations(ApiHeader::class) as $declaration) {
            /** @var ApiHeader $attribute */
            $attribute = $declaration['instance'];
            $declared = $declaration['declared'];

            $this->declared['headers'][$attribute->name] = array_filter([
                'value' => in_array('value', $declared, true) ? $attribute->value : null,
                'description' => in_array('description', $declared, true) ? $attribute->description : null,
            ], fn ($value) => $value !== null);

            $existing = null;

            foreach ($endpoint->headers as $header) {
                if (strcasecmp($header->name, $attribute->name) === 0) {
                    $existing = $header;
                    break;
                }
            }

            if ($existing === null) {
                $endpoint->addHeader(new HeaderParam(
                    name: $attribute->name,
                    value: $attribute->value,
                    required: $attribute->required,
                    description: $attribute->description,
                ));

                continue;
            }

            // Field by field, so naming a header does not blank its value.
            foreach ($declared as $field) {
                match ($field) {
                    'value' => $existing->value = $attribute->value,
                    'required' => $existing->required = $attribute->required,
                    'description' => $existing->description = $attribute->description,
                    default => null,
                };
            }
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

            $this->declared['responses'][(string) $attribute->status] = array_filter([
                'content' => $attribute->content,
                'description' => $attribute->description === '' ? null : $attribute->description,
            ], fn ($value) => $value !== null);

            $endpoint->addResponse(new ResponseExample(
                status: $attribute->status,
                content: $attribute->content,
                description: $attribute->description,
                contentType: $attribute->contentType,
            ));
        }
    }
}
