<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Data\ResponseExample;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Cofa\ApiDocs\Support\DocBlock;
use Cofa\ApiDocs\Support\DocBlockParser;
use Cofa\ApiDocs\Support\ExampleFactory;
use Cofa\ApiDocs\Support\ParameterTree;

/**
 * Reads the hand written documentation: the docblock on the controller class
 * and on the action. Anything declared here wins over what is inferred.
 */
class DocBlockExtractor implements Extractor
{
    /** What this docblock actually declared, for the precedence check. */
    protected array $declared = [];

    public function __construct(protected DocBlockParser $parser = new DocBlockParser())
    {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        $this->declared = [];

        $class = $context->classDocBlock();
        $method = $context->methodDocBlock();

        $this->applyGroup($endpoint, $class, $method);
        $this->applyText($endpoint, $method);
        $this->applyFlags($endpoint, $class, $method);
        $this->applyParameters($endpoint, $method);
        $this->applyHeaders($endpoint, $class, $method);
        $this->applyResponses($endpoint, $context, $method);

        if (($operationId = $method->tag('operationid')) !== null && $operationId !== '') {
            $endpoint->meta['operation_id'] = $operationId;
        }

        foreach ($method->tags('tag') as $tag) {
            if ($tag !== '') {
                $endpoint->meta['tags'][] = $tag;
            }
        }

        if (($operationId = $method->tag('operationid')) !== null && $operationId !== '') {
            $this->declared['operation']['operationId'] = $operationId;
        }

        $endpoint->meta['declared']['docblock'] = $this->declared;
    }

    protected function applyGroup(Endpoint $endpoint, DocBlock $class, DocBlock $method): void
    {
        $group = $method->tag('group') ?? $class->tag('group');

        if ($group !== null && trim($group) !== '') {
            $lines = preg_split('/\R/', trim($group)) ?: [];
            $endpoint->group = trim((string) array_shift($lines));
            $this->declared['operation']['group'] = $endpoint->group;

            $description = trim(implode("\n", $lines));

            if ($description !== '') {
                $endpoint->meta['group_description'] = $description;
            }
        }

        $subgroup = $method->tag('subgroup') ?? $class->tag('subgroup');

        if ($subgroup !== null && trim($subgroup) !== '') {
            $endpoint->subgroup = trim($subgroup);
        }
    }

    protected function applyText(Endpoint $endpoint, DocBlock $method): void
    {
        $summary = $method->tag('summary') ?? $method->tag('title');

        if ($summary !== null && trim($summary) !== '') {
            $endpoint->title = trim($summary);
            $this->declared['operation']['summary'] = $endpoint->title;
        } elseif ($method->summary !== '') {
            $endpoint->title = $method->summary;
            $this->declared['operation']['summary'] = $endpoint->title;
        }

        $description = $method->tag('description');

        if ($description !== null && trim($description) !== '') {
            $endpoint->description = trim($description);
            $this->declared['operation']['description'] = $endpoint->description;
        } elseif ($method->description !== '') {
            $endpoint->description = $method->description;
            $this->declared['operation']['description'] = $endpoint->description;
        }
    }

    protected function applyFlags(Endpoint $endpoint, DocBlock $class, DocBlock $method): void
    {
        if ($method->hasTag('authenticated') || $class->hasTag('authenticated')) {
            $endpoint->authenticated = true;
            $this->declared['operation']['authenticated'] = true;
        }

        if ($method->hasTag('unauthenticated')) {
            $endpoint->authenticated = false;
            $this->declared['operation']['authenticated'] = false;
        }

        if ($method->hasTag('deprecated') || $class->hasTag('deprecated')) {
            $endpoint->deprecated = true;
            $this->declared['operation']['deprecated'] = true;
            $note = $method->tag('deprecated') ?? $class->tag('deprecated');
            $endpoint->deprecationNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        }
    }

    protected function applyParameters(Endpoint $endpoint, DocBlock $method): void
    {
        $buckets = [
            'bodyparam' => 'bodyParameters',
            'queryparam' => 'queryParameters',
            'urlparam' => 'urlParameters',
        ];

        foreach ($buckets as $tag => $bucket) {
            $parameters = [];

            foreach ($method->tags($tag) as $value) {
                $parsed = $this->parseParam($value);

                if ($parsed === null) {
                    continue;
                }

                $this->declared[$bucket][$parsed->name] = $this->valuesOf($parsed);
                $parameters[] = $parsed;
            }

            if ($parameters === []) {
                continue;
            }

            $endpoint->mergeParameters(
                $bucket,
                $bucket === 'urlParameters' ? $parameters : ParameterTree::nest($parameters),
                preferNew: true
            );
        }
    }

    protected function parseParam(string $value): ?Parameter
    {
        $parsed = $this->parser->parseParamTag($value);

        if ($parsed === null) {
            return null;
        }

        $parameter = new Parameter(
            name: $parsed['name'],
            type: $parsed['type'],
            required: $parsed['required'],
            description: $parsed['description'],
            example: $parsed['example'],
            declared: $parsed['declared'],
        );

        if ($parameter->example === null) {
            $parameter->example = ExampleFactory::forParameter(
                $parameter->name,
                $parameter->type
            );
        }

        return $parameter;
    }

    /**
     * The values a parameter definition actually stated, keyed by field.
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

    protected function applyHeaders(Endpoint $endpoint, DocBlock $class, DocBlock $method): void
    {
        foreach (array_merge($class->tags('header'), $method->tags('header')) as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $value, 2) ?: [];
            $name = $parts[0] ?? '';

            if ($name === '') {
                continue;
            }

            $rest = trim($parts[1] ?? '');
            $description = '';

            // "X-Locale en  The preferred locale." – value first, then prose.
            if ($rest !== '' && str_contains($rest, '  ')) {
                [$rest, $description] = array_map('trim', explode('  ', $rest, 2));
            }

            $header = new HeaderParam(
                name: rtrim($name, ':'),
                value: $rest,
                required: true,
                description: $description,
            );

            $this->declared['headers'][$header->name] = array_filter([
                'value' => $header->value === '' ? null : $header->value,
                'description' => $header->description === '' ? null : $header->description,
            ], fn ($value) => $value !== null);

            $endpoint->addHeader($header);
        }
    }

    protected function applyResponses(Endpoint $endpoint, RouteContext $context, DocBlock $method): void
    {
        $parser = $context->parser();

        foreach ($method->tags('response') as $value) {
            $parsed = $parser->parseResponseTag($value);

            $this->declared['responses'][(string) $parsed['status']] = array_filter([
                'content' => $parsed['content'],
                'description' => $parsed['description'] === '' ? null : $parsed['description'],
            ], fn ($value) => $value !== null);

            $endpoint->addResponse(new ResponseExample(
                status: $parsed['status'],
                content: $parsed['content'],
                description: $parsed['description'],
            ));
        }

        foreach (['responseresource', 'apiresource'] as $tag) {
            foreach ($method->tags($tag) as $value) {
                $this->rememberResourceTag($endpoint, $value, collection: false);
            }
        }

        foreach (['responseresourcecollection', 'apiresourcecollection'] as $tag) {
            foreach ($method->tags($tag) as $value) {
                $this->rememberResourceTag($endpoint, $value, collection: true);
            }
        }
    }

    protected function rememberResourceTag(Endpoint $endpoint, string $value, bool $collection): void
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $status = 200;
        $class = null;

        foreach ($parts as $part) {
            if (preg_match('/^\d{3}$/', $part) === 1) {
                $status = (int) $part;

                continue;
            }

            if ($part !== '') {
                $class ??= ltrim($part, '\\');
            }
        }

        if ($class === null) {
            return;
        }

        $endpoint->meta['resource_responses'][] = [
            'status' => $status,
            'class' => $class,
            'collection' => $collection,
        ];
    }
}
