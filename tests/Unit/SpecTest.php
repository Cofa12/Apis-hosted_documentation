<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\OpenApi\Spec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The renderer reads an OpenAPI document, not this package's internals, so it
 * has to cope with a spec written by someone else.
 */
class SpecTest extends TestCase
{
    protected function spec(): Spec
    {
        return Spec::fromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Petstore', 'version' => '9.9.9', 'description' => 'A third party API.'],
            'servers' => [['url' => 'https://petstore.test/v1/']],
            'tags' => [
                ['name' => 'Pets', 'description' => 'Everything about pets.'],
                ['name' => 'Store'],
            ],
            'paths' => [
                '/pets/{petId}' => [
                    'parameters' => [
                        ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ],
                    'get' => [
                        'tags' => ['Pets'],
                        'summary' => 'Find a pet',
                        'operationId' => 'showPetById',
                        'deprecated' => true,
                        'security' => [['apiKey' => []]],
                        'parameters' => [
                            ['$ref' => '#/components/parameters/Verbose'],
                            ['name' => 'X-Trace', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'examples' => ['abc']]],
                        ],
                        'responses' => [
                            '404' => ['description' => 'Not found'],
                            '200' => [
                                'description' => 'A pet',
                                'headers' => ['X-Rate-Limit' => ['description' => 'Calls left', 'schema' => ['type' => 'string', 'examples' => ['59']]]],
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                            ],
                        ],
                    ],
                ],
                '/pets' => [
                    'post' => [
                        'tags' => ['Pets'],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]],
                        ],
                        'responses' => ['201' => ['description' => 'Created']],
                    ],
                ],
            ],
            'components' => [
                'parameters' => [
                    'Verbose' => ['name' => 'verbose', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
                ],
                'securitySchemes' => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']],
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['id', 'name'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'examples' => [7]],
                            'name' => ['type' => 'string', 'examples' => ['Rex']],
                            'tag' => ['type' => ['string', 'null'], 'description' => 'Optional label.'],
                            'owner' => [
                                'type' => 'object',
                                'properties' => ['email' => ['type' => 'string', 'format' => 'email', 'examples' => ['a@b.test']]],
                            ],
                            'photos' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function it_reads_the_document_metadata(): void
    {
        $spec = $this->spec();

        $this->assertSame('Petstore', $spec->title());
        $this->assertSame('9.9.9', $spec->version());
        $this->assertSame('A third party API.', $spec->description());
        $this->assertSame('3.0.3', $spec->openapiVersion());
        $this->assertSame('https://petstore.test/v1', $spec->baseUrl(), 'The trailing slash is trimmed.');
    }

    #[Test]
    public function it_lists_operations_grouped_by_tag_in_document_order(): void
    {
        $groups = $this->spec()->groupedOperations();

        $this->assertSame(['Pets'], array_keys($groups));
        $this->assertCount(2, $groups['Pets']);
        $this->assertSame(2, $this->spec()->operationCount());
        $this->assertSame('Everything about pets.', $this->spec()->tagDescription('Pets'));
    }

    #[Test]
    public function an_operation_exposes_its_metadata(): void
    {
        $operation = $this->spec()->operations()[0];

        $this->assertSame('GET', $operation->method);
        $this->assertSame('/pets/{petId}', $operation->path);
        $this->assertSame('Find a pet', $operation->summary());
        $this->assertSame('showPetById', $operation->operationId());
        $this->assertSame('get-pets-petid', $operation->id());
        $this->assertTrue($operation->isDeprecated());
        $this->assertTrue($operation->isAuthenticated());
        $this->assertSame(['apiKey'], $operation->securitySchemes());
    }

    #[Test]
    public function it_merges_path_level_parameters_and_resolves_references(): void
    {
        $operation = $this->spec()->operations()[0];

        $this->assertSame(['petId'], array_column($operation->pathParameters(), 'name'));
        $this->assertSame(['verbose'], array_column($operation->queryParameters(), 'name'), 'A $ref parameter is resolved.');
        $this->assertSame(['X-Trace'], array_column($operation->headers(), 'name'));
    }

    #[Test]
    public function it_substitutes_path_parameters_with_their_examples(): void
    {
        $operation = $this->spec()->operations()[0];

        $this->assertStringContainsString('/pets/', $operation->resolvedPath());
        $this->assertStringNotContainsString('{petId}', $operation->resolvedPath());
        $this->assertStringStartsWith('https://petstore.test/v1/pets/', $operation->resolvedUrl());
    }

    #[Test]
    public function responses_are_sorted_and_split_by_outcome(): void
    {
        $operation = $this->spec()->operations()[0];

        $this->assertSame(['200', '404'], array_map(fn ($r) => $r->status, $operation->responses()));
        $this->assertCount(1, $operation->successResponses());
        $this->assertCount(1, $operation->errorResponses());
        $this->assertSame('Calls left', $operation->responses()[0]->headers()[0]['description']);
        $this->assertSame('59', $operation->responses()[0]->headers()[0]['example']);
    }

    #[Test]
    public function a_referenced_response_schema_is_resolved_and_exampled(): void
    {
        $response = $this->spec()->operations()[0]->successResponses()[0];
        $example = $response->example();

        $this->assertSame(7, $example['id']);
        $this->assertSame('Rex', $example['name']);
        $this->assertSame('a@b.test', $example['owner']['email']);
        $this->assertSame(['string'], $example['photos']);
        $this->assertStringContainsString('"name": "Rex"', $response->body());
    }

    #[Test]
    public function a_schema_flattens_into_table_rows(): void
    {
        $schema = $this->spec()->operations()[0]->successResponses()[0]->schema();
        $rows = $schema->rows();

        $paths = array_column($rows, 'path');
        $this->assertSame(['id', 'name', 'tag', 'owner', 'owner.email', 'photos'], $paths);

        $this->assertSame(0, $rows[0]['depth']);
        $this->assertTrue($rows[0]['required']);
        $this->assertSame(1, $rows[4]['depth'], 'Nested fields are indented.');
        $this->assertFalse($rows[2]['required']);
    }

    #[Test]
    public function it_renders_readable_types(): void
    {
        $schema = $this->spec()->operations()[0]->successResponses()[0]->schema();
        $properties = $schema->properties();

        $this->assertSame('integer', $properties['id']->type());
        $this->assertSame('string | null', $properties['tag']->type());
        $this->assertSame('string[]', $properties['photos']->type());
        $this->assertSame('string<email>', $properties['owner']->properties()['email']->type());
        $this->assertTrue($properties['tag']->isNullable());
        $this->assertTrue($properties['owner']->isObject());
        $this->assertTrue($properties['photos']->isArray());
    }

    #[Test]
    public function the_request_body_is_exposed_with_its_schema(): void
    {
        $operation = $this->spec()->operations()[1];

        $this->assertSame('application/json', $operation->requestMediaType());
        $this->assertTrue($operation->requestBodyRequired());
        $this->assertTrue($operation->hasBody());
        $this->assertSame('Pet', $operation->requestSchema()->refName());
        $this->assertStringContainsString('"name": "Rex"', $operation->requestExampleJson());
    }

    #[Test]
    public function an_unresolvable_reference_does_not_explode(): void
    {
        $spec = Spec::fromArray([
            'paths' => ['/x' => ['get' => [
                'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]]]],
            ]]],
        ]);

        $schema = $spec->operations()[0]->successResponses()[0]->schema();

        $this->assertSame('any', $schema->type());
        $this->assertSame([], $schema->rows());
        $this->assertNull($spec->resolveRef('#/components/schemas/Missing'));
        $this->assertNull($spec->resolveRef('https://example.com/other.json#/Pet'));
    }

    #[Test]
    public function it_survives_a_document_with_nothing_in_it(): void
    {
        $spec = Spec::fromArray([]);

        $this->assertTrue($spec->isEmpty());
        $this->assertSame('API Documentation', $spec->title());
        $this->assertSame([], $spec->groupedOperations());
    }

    #[Test]
    public function it_reads_a_document_from_json(): void
    {
        $spec = Spec::fromJson('{"info":{"title":"From file"}}');

        $this->assertSame('From file', $spec->title());

        $this->expectException(RuntimeException::class);
        Spec::fromJson('not json');
    }

    #[Test]
    public function it_reads_and_writes_yaml(): void
    {
        $path = sys_get_temp_dir() . '/api-docs-spec-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($path, $this->spec()->toYaml());

        try {
            $spec = Spec::fromFile($path);

            $this->assertSame('Petstore', $spec->title());
            $this->assertSame(2, $spec->operationCount());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function a_missing_file_is_reported_clearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAPI document not found');

        Spec::fromFile('/tmp/definitely-not-here-' . bin2hex(random_bytes(4)) . '.json');
    }
}
