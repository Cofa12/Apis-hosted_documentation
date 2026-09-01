<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RouteScannerTest extends TestCase
{
    #[Test]
    public function it_documents_every_api_route_and_skips_the_rest(): void
    {
        $uris = array_map(fn ($endpoint) => $endpoint->method() . ' ' . $endpoint->uri, $this->endpoints());

        $this->assertContains('GET api/users', $uris);
        $this->assertContains('POST api/users', $uris);
        $this->assertContains('GET api/users/{user}', $uris);
        $this->assertContains('DELETE api/users/{user}', $uris);
        $this->assertContains('GET api/health', $uris);
        $this->assertContains('GET api/ping', $uris, 'Closure routes are documented too.');

        $this->assertNotContains('GET web/home', $uris, 'Non API routes are filtered out.');
        $this->assertNotContains('GET api/users/internal', $uris, '@ignore hides an endpoint.');
    }

    #[Test]
    public function it_groups_endpoints_from_docblocks_and_attributes(): void
    {
        $this->assertSame('Users', $this->endpoint('GET', 'api/users')->group);
        $this->assertSame('System', $this->endpoint('GET', 'api/health')->group);
        $this->assertSame('Legacy', $this->endpoint('POST', 'api/legacy/export')->group);
    }

    #[Test]
    public function it_reads_titles_and_descriptions_from_docblocks(): void
    {
        $endpoint = $this->endpoint('GET', 'api/users');

        $this->assertSame('List users', $endpoint->title);
        $this->assertStringContainsString('paginated list', $endpoint->description);
    }

    #[Test]
    public function it_reads_the_summary_from_a_php_attribute(): void
    {
        $endpoint = $this->endpoint('GET', 'api/health');

        $this->assertSame('Health check', $endpoint->title);
        $this->assertSame('Reports whether the API is reachable.', $endpoint->description);
    }

    #[Test]
    public function it_extracts_body_parameters_from_a_form_request(): void
    {
        $endpoint = $this->endpoint('POST', 'api/users');
        $names = array_map(fn (Parameter $p) => $p->name, $endpoint->bodyParameters);

        $this->assertContains('name', $names);
        $this->assertContains('email', $names);
        $this->assertContains('password', $names);
        $this->assertContains('password_confirmation', $names, 'The confirmed rule adds a second field.');
        $this->assertContains('address', $names);
        $this->assertContains('tags', $names);

        $name = $this->parameter($endpoint->bodyParameters, 'name');
        $this->assertTrue($name->required);
        $this->assertSame('string', $name->type);
        $this->assertStringContainsString('at least 2 characters', $name->description);
        $this->assertSame('Ada Lovelace', $name->example, 'bodyParameters() overrides the generated example.');
        $this->assertStringContainsString('The full name of the user.', $name->description);
    }

    #[Test]
    public function it_nests_dotted_validation_rules(): void
    {
        $endpoint = $this->endpoint('POST', 'api/users');

        $address = $this->parameter($endpoint->bodyParameters, 'address');
        $this->assertSame('object', $address->type);
        $this->assertSame(['city', 'zip'], array_map(fn (Parameter $p) => $p->name, $address->children));
        $this->assertTrue($this->parameter($address->children, 'city')->required);

        $contacts = $this->parameter($endpoint->bodyParameters, 'contacts');
        $this->assertSame('object[]', $contacts->type);
        $this->assertSame(['name', 'phone'], array_map(fn (Parameter $p) => $p->name, $contacts->children));

        $tags = $this->parameter($endpoint->bodyParameters, 'tags');
        $this->assertSame('string[]', $tags->type, 'tags.* describes the items of tags.');
    }

    #[Test]
    public function it_understands_rule_objects_and_enums(): void
    {
        $endpoint = $this->endpoint('POST', 'api/users');

        $status = $this->parameter($endpoint->bodyParameters, 'status');
        $this->assertSame(['active', 'suspended'], $status->enum);
        $this->assertSame('active', $status->example);

        $role = $this->parameter($endpoint->bodyParameters, 'role');
        $this->assertSame(['active', 'suspended', 'banned'], $role->enum, 'Rule::enum() is expanded to its cases.');
    }

    #[Test]
    public function it_extracts_inline_validation_from_the_controller(): void
    {
        $endpoint = $this->endpoint('PUT', 'api/users/{user}');
        $names = array_map(fn (Parameter $p) => $p->name, $endpoint->bodyParameters);

        $this->assertSame(['name', 'email'], $names);
        $this->assertFalse($this->parameter($endpoint->bodyParameters, 'name')->required);
    }

    #[Test]
    public function it_extracts_query_parameters_and_pagination(): void
    {
        $endpoint = $this->endpoint('GET', 'api/users');
        $names = array_map(fn (Parameter $p) => $p->name, $endpoint->queryParameters);

        $this->assertContains('search', $names);
        $this->assertContains('sort', $names);
        $this->assertContains('admins', $names);
        $this->assertContains('page', $names);
        $this->assertContains('per_page', $names);

        $this->assertSame('boolean', $this->parameter($endpoint->queryParameters, 'admins')->type);
        $this->assertSame('created_at', $this->parameter($endpoint->queryParameters, 'sort')->default);
        $this->assertSame(25, $this->parameter($endpoint->queryParameters, 'per_page')->example);
        $this->assertSame('active', $this->parameter($endpoint->queryParameters, 'status')->example, '@queryParam is merged in.');
    }

    #[Test]
    public function it_documents_url_parameters_from_route_model_binding(): void
    {
        $endpoint = $this->endpoint('GET', 'api/users/{user}');
        $user = $this->parameter($endpoint->urlParameters, 'user');

        $this->assertTrue($user->required);
        $this->assertSame('integer', $user->type);
        $this->assertStringContainsString('user', $user->description);
    }

    #[Test]
    public function it_detects_authentication_and_adds_the_authorization_header(): void
    {
        $endpoint = $this->endpoint('POST', 'api/users');

        $this->assertTrue($endpoint->authenticated);

        $names = array_map(fn ($header) => $header->name, $endpoint->headers);
        $this->assertContains('Authorization', $names);
        $this->assertContains('Accept', $names);
        $this->assertContains('Content-Type', $names);
        $this->assertContains('X-Tenant', $names, 'The #[ApiHeader] attribute is honoured.');

        $this->assertFalse($this->endpoint('GET', 'api/health')->authenticated);
    }

    #[Test]
    public function it_infers_responses_from_api_resources(): void
    {
        $endpoint = $this->endpoint('GET', 'api/users/{user}');
        $response = $endpoint->successResponses()[0];

        $this->assertSame(200, $response->status);
        $this->assertArrayHasKey('data', $response->content);
        $this->assertSame(1, $response->content['data']['id']);
        $this->assertSame('john@example.com', $response->content['data']['email']);
        $this->assertArrayHasKey('profile', $response->content['data']);
        $this->assertArrayHasKey('posts', $response->content['data'], 'whenLoaded() relations are followed.');
        $this->assertSame('UserResource', $response->schemaName);
    }

    #[Test]
    public function it_wraps_paginated_collections(): void
    {
        $response = $this->endpoint('GET', 'api/users')->successResponses()[0];

        $this->assertArrayHasKey('data', $response->content);
        $this->assertArrayHasKey('meta', $response->content);
        $this->assertArrayHasKey('links', $response->content);
        $this->assertTrue($response->collection);
    }

    #[Test]
    public function it_reads_explicit_response_annotations(): void
    {
        $response = $this->endpoint('GET', 'api/health')->successResponses()[0];

        $this->assertSame(200, $response->status);
        $this->assertSame(['status' => 'ok', 'uptime' => 128456], $response->content);
    }

    #[Test]
    public function it_documents_the_error_paths(): void
    {
        $statuses = array_map(fn ($r) => $r->status, $this->endpoint('POST', 'api/users')->responses);

        $this->assertContains(201, $statuses);
        $this->assertContains(401, $statuses, 'auth middleware implies a 401.');
        $this->assertContains(403, $statuses, 'the form request authorizes, so a 403 is possible.');
        $this->assertContains(422, $statuses, 'validated payloads can fail.');
        $this->assertContains(429, $statuses, 'throttle middleware implies a 429.');

        $destroy = array_map(fn ($r) => $r->status, $this->endpoint('DELETE', 'api/users/{user}')->responses);
        $this->assertContains(204, $destroy);
        $this->assertContains(403, $destroy, 'abort_if() is documented.');
        $this->assertContains(404, $destroy, 'route model binding can miss.');
    }

    #[Test]
    public function it_flags_deprecated_endpoints(): void
    {
        $endpoint = $this->endpoint('POST', 'api/legacy/export');

        $this->assertTrue($endpoint->deprecated);
        $this->assertSame('Use the reports endpoint instead.', $endpoint->deprecationNote);
        $this->assertSame('csv', $this->parameter($endpoint->bodyParameters, 'format')->example);
        $this->assertSame(202, $endpoint->successResponses()[0]->status);
    }

    #[Test]
    public function it_never_throws_on_routes_it_cannot_read(): void
    {
        $this->assertSame([], $this->app->make(\Cofa\ApiDocs\DocumentationGenerator::class)->errors());
    }

    /** @param array<int, Parameter> $parameters */
    protected function parameter(array $parameters, string $name): Parameter
    {
        foreach ($parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        $this->fail("No parameter named [{$name}]. Found: " . implode(', ', array_map(fn (Parameter $p) => $p->name, $parameters)));
    }
}
