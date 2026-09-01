<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class OpenApiDocumentTest extends TestCase
{
    #[Test]
    public function it_builds_a_valid_openapi_skeleton(): void
    {
        $document = $this->spec()->toArray();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('Example API', $document['info']['title']);
        $this->assertSame('2.4.0', $document['info']['version']);
        $this->assertSame('https://api.example.test', $document['servers'][0]['url']);
        $this->assertArrayHasKey('paths', $document);
        $this->assertArrayHasKey('components', $document);
    }

    #[Test]
    public function it_lists_every_tag_it_documented(): void
    {
        $tags = array_column($this->spec()->toArray()['tags'], 'name');

        $this->assertContains('Users', $tags);
        $this->assertContains('System', $tags);
        $this->assertContains('Legacy', $tags);
    }

    #[Test]
    public function it_uses_openapi_path_templating(): void
    {
        $paths = array_keys($this->spec()->toArray()['paths']);

        $this->assertContains('/api/users', $paths);
        $this->assertContains('/api/users/{user}', $paths);
    }

    #[Test]
    public function it_gives_every_operation_a_unique_operation_id(): void
    {
        $ids = array_map(fn ($operation) => $operation->operationId(), $this->spec()->operations());

        $this->assertSame(array_unique($ids), $ids);
        $this->assertContains('getApiUsers', $ids);
        $this->assertContains('postApiUsers', $ids);
        $this->assertContains('deleteApiUsersUser', $ids);
    }

    #[Test]
    public function it_describes_path_and_query_parameters(): void
    {
        $operation = $this->spec()->toArray()['paths']['/api/users']['get'];
        $query = array_values(array_filter($operation['parameters'], fn ($p) => $p['in'] === 'query'));
        $names = array_column($query, 'name');

        $this->assertContains('search', $names);
        $this->assertContains('page', $names);

        $show = $this->spec()->toArray()['paths']['/api/users/{user}']['get'];
        $path = array_values(array_filter($show['parameters'], fn ($p) => $p['in'] === 'path'));

        $this->assertSame('user', $path[0]['name']);
        $this->assertTrue($path[0]['required'], 'Path parameters are always required in OpenAPI.');
        $this->assertSame('integer', $path[0]['schema']['type']);
    }

    #[Test]
    public function it_keeps_reserved_headers_out_of_the_parameter_list(): void
    {
        $operation = $this->spec()->toArray()['paths']['/api/users']['post'];
        $headers = array_column(
            array_values(array_filter($operation['parameters'], fn ($p) => $p['in'] === 'header')),
            'name'
        );

        $this->assertContains('X-Tenant', $headers);
        $this->assertNotContains('Authorization', $headers, 'Authorization is expressed through security.');
        $this->assertNotContains('Content-Type', $headers);
        $this->assertNotContains('Accept', $headers);

        $extension = array_column($operation['x-headers'], 'name');
        $this->assertContains('Authorization', $extension, 'Nothing is lost: it moves to x-headers.');
    }

    #[Test]
    public function it_builds_a_request_body_schema_from_the_validation_rules(): void
    {
        $body = $this->spec()->toArray()['paths']['/api/users']['post']['requestBody'];
        // The endpoint accepts an avatar, so the payload is multipart.
        $schema = $body['content']['multipart/form-data']['schema'];

        $this->assertTrue($body['required']);
        $this->assertSame('object', $schema['type']);
        $this->assertContains('name', $schema['required']);
        $this->assertContains('email', $schema['required']);
        $this->assertNotContains('age', $schema['required']);

        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame(2, $schema['properties']['name']['minLength']);
        $this->assertSame(60, $schema['properties']['name']['maxLength']);
        $this->assertSame('email', $schema['properties']['email']['format']);
        $this->assertSame(['active', 'suspended'], $schema['properties']['status']['enum']);
        $this->assertSame(['integer', 'null'], $schema['properties']['age']['type'], 'Nullable is a union type in 3.1.');
        $this->assertSame(18, $schema['properties']['age']['minimum']);

        $this->assertSame('object', $schema['properties']['address']['type']);
        $this->assertSame('string', $schema['properties']['address']['properties']['city']['type']);

        $this->assertSame('array', $schema['properties']['contacts']['type']);
        $this->assertSame('object', $schema['properties']['contacts']['items']['type']);

        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame('string', $schema['properties']['tags']['items']['type']);

        $this->assertSame('binary', $schema['properties']['avatar']['format']);
    }

    #[Test]
    public function it_ships_a_request_example_next_to_the_schema(): void
    {
        $example = $this->spec()->toArray()['paths']['/api/users']['post']['requestBody']['content']['multipart/form-data']['example'];

        $this->assertSame('Ada Lovelace', $example['name']);
        $this->assertSame('john@example.com', $example['email']);
        $this->assertSame(['city' => 'London', 'zip' => '10001'], $example['address']);
    }

    #[Test]
    public function it_switches_to_multipart_when_a_file_is_uploaded(): void
    {
        $content = $this->spec()->toArray()['paths']['/api/users']['post']['requestBody']['content'];

        $this->assertSame(['multipart/form-data'], array_keys($content));

        // A payload without files stays JSON.
        $legacy = $this->spec()->toArray()['paths']['/api/legacy/export']['post']['requestBody']['content'];
        $this->assertSame(['application/json'], array_keys($legacy));
    }

    #[Test]
    public function it_registers_resources_as_reusable_component_schemas(): void
    {
        $document = $this->spec()->toArray();

        $this->assertArrayHasKey('UserResource', $document['components']['schemas']);
        $this->assertArrayHasKey('ValidationErrorResponse', $document['components']['schemas']);

        $show = $document['paths']['/api/users/{user}']['get']['responses']['200'];
        $schema = $show['content']['application/json']['schema'];

        $this->assertSame('#/components/schemas/UserResource', $schema['properties']['data']['$ref']);

        $index = $document['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('array', $index['properties']['data']['type']);
        $this->assertSame('#/components/schemas/UserResource', $index['properties']['data']['items']['$ref']);
        $this->assertArrayHasKey('meta', $index['properties'], 'The pagination envelope survives.');
    }

    #[Test]
    public function it_expresses_authentication_with_a_security_requirement(): void
    {
        $document = $this->spec()->toArray();

        $this->assertSame(
            ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Bearer token issued by the authentication endpoints.'],
            $document['components']['securitySchemes']['bearerAuth']
        );

        $this->assertSame([['bearerAuth' => []]], $document['paths']['/api/users']['post']['security']);
        $this->assertSame([], $document['paths']['/api/health']['get']['security'], 'Public endpoints opt out explicitly.');
    }

    #[Test]
    public function it_marks_deprecated_operations(): void
    {
        $operation = $this->spec()->toArray()['paths']['/api/legacy/export']['post'];

        $this->assertTrue($operation['deprecated']);
        $this->assertStringContainsString('Use the reports endpoint instead.', $operation['description']);
    }

    #[Test]
    public function it_documents_a_no_content_response_without_a_body(): void
    {
        $responses = $this->spec()->toArray()['paths']['/api/users/{user}']['delete']['responses'];

        $this->assertArrayHasKey('204', $responses);
        $this->assertArrayNotHasKey('content', $responses['204']);
        $this->assertSame('The user was removed.', $responses['204']['description']);
    }

    #[Test]
    public function the_document_survives_a_json_round_trip(): void
    {
        $spec = $this->spec();
        $decoded = json_decode($spec->toJson(), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame($spec->toArray(), $decoded);
    }
}
