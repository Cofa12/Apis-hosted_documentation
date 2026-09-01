<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DocumentationPageTest extends TestCase
{
    #[Test]
    public function it_serves_the_documentation_page(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('Example API', escape: false);
        $response->assertSee('OpenAPI 3.1.0', escape: false);
    }

    #[Test]
    public function it_renders_every_endpoint_with_its_method_and_path(): void
    {
        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringContainsString('id="get-api-users"', $html);
        $this->assertStringContainsString('id="post-api-users"', $html);
        $this->assertStringContainsString('id="delete-api-users-user"', $html);
        $this->assertStringContainsString('<span class="method get">GET</span>', $html);
        $this->assertStringContainsString('<span class="method delete">DELETE</span>', $html);
        $this->assertStringContainsString('/api/users/<span class="var">{user}</span>', $html);
    }

    #[Test]
    public function it_renders_the_groups_in_the_sidebar(): void
    {
        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringContainsString('>Users<', $html);
        $this->assertStringContainsString('>System<', $html);
        $this->assertStringContainsString('>Legacy<', $html);
        $this->assertStringContainsString('href="#get-api-health"', $html);
    }

    #[Test]
    public function it_renders_parameters_headers_and_bodies(): void
    {
        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringContainsString('URL parameters', $html);
        $this->assertStringContainsString('Query parameters', $html);
        $this->assertStringContainsString('Headers', $html);
        $this->assertStringContainsString('X-Tenant', $html);
        $this->assertStringContainsString('password_confirmation', $html);
        $this->assertStringContainsString('Must be at least 8 characters.', $html);
        $this->assertStringContainsString('<span class="req">required</span>', $html);
        $this->assertStringContainsString('<span class="pill enum">active</span>', $html);
    }

    #[Test]
    public function it_renders_highlighted_response_examples(): void
    {
        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringContainsString('Responses', $html);
        $this->assertStringContainsString('tok-key', $html);
        $this->assertStringContainsString('Unauthenticated.', $html);
        $this->assertStringContainsString('The payload failed validation.', $html);
    }

    #[Test]
    public function it_renders_code_samples_for_each_configured_language(): void
    {
        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringContainsString('curl --request POST', $html);
        $this->assertStringContainsString('await fetch(', $html);
        $this->assertStringContainsString('GuzzleHttp\\Client', $html);
        $this->assertStringContainsString('https://api.example.test/api/users', $html);
    }

    #[Test]
    public function it_renders_the_try_it_console_when_enabled(): void
    {
        $this->assertStringContainsString('<form class="tryit"', $this->get('/api/documentation')->getContent());

        $this->withConfig(['api-docs.ui.try_it' => false]);

        $this->assertStringNotContainsString('<form class="tryit"', $this->get('/api/documentation')->getContent());
    }

    #[Test]
    public function it_exposes_the_raw_openapi_document(): void
    {
        $response = $this->getJson('/api/documentation.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.1.0');
        $response->assertJsonPath('info.title', 'Example API');
        $response->assertJsonStructure(['openapi', 'info', 'servers', 'paths', 'components']);
    }

    #[Test]
    public function the_documentation_route_can_be_disabled(): void
    {
        $this->withConfig(['api-docs.serve.enabled' => false]);

        $this->get('/api/documentation')->assertNotFound();
    }

    #[Test]
    public function the_documentation_route_is_not_documented_by_itself(): void
    {
        $paths = array_keys($this->spec()->toArray()['paths']);

        $this->assertNotContains('/api/documentation', $paths);
        $this->assertNotContains('/api/documentation.json', $paths);
    }
}
