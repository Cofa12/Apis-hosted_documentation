<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class GenerateCommandTest extends TestCase
{
    protected string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir() . '/api-docs-test-' . bin2hex(random_bytes(4));

        $this->withConfig([
            'api-docs.output.views_path' => $this->output . '/views',
            'api-docs.output.spec_file' => $this->output . '/views/openapi.json',
            'api-docs.output.static_html' => $this->output . '/public/index.html',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->output !== '' && File::isDirectory($this->output)) {
            File::deleteDirectory($this->output);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_writes_the_spec_and_the_blade_templates_into_the_project(): void
    {
        $this->artisan('api-docs:generate')
            ->assertSuccessful()
            ->expectsOutputToContain('Documented');

        $this->assertFileExists($this->output . '/views/openapi.json');
        $this->assertFileExists($this->output . '/views/documentation.blade.php');
        $this->assertFileExists($this->output . '/views/layout.blade.php');
        $this->assertFileExists($this->output . '/views/partials/operation.blade.php');
        $this->assertFileExists($this->output . '/views/assets/styles.blade.php');

        $document = json_decode(File::get($this->output . '/views/openapi.json'), true);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/users', $document['paths']);
    }

    #[Test]
    public function it_keeps_local_template_edits_unless_forced(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $template = $this->output . '/views/layout.blade.php';
        File::put($template, 'CUSTOMISED');

        $this->artisan('api-docs:generate')->assertSuccessful();
        $this->assertSame('CUSTOMISED', File::get($template));

        $this->artisan('api-docs:generate --force')->assertSuccessful();
        $this->assertNotSame('CUSTOMISED', File::get($template));
    }

    #[Test]
    public function it_can_skip_the_templates_entirely(): void
    {
        $this->artisan('api-docs:generate --no-views')->assertSuccessful();

        $this->assertFileExists($this->output . '/views/openapi.json');
        $this->assertFileDoesNotExist($this->output . '/views/layout.blade.php');
    }

    #[Test]
    public function it_can_render_a_standalone_html_build(): void
    {
        $this->artisan('api-docs:generate --static')->assertSuccessful();

        $html = File::get($this->output . '/public/index.html');

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Example API', $html);
        $this->assertStringContainsString('id="post-api-users"', $html);
        $this->assertStringNotContainsString('<script src=', $html, 'The build has no external dependencies.');
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
    }

    #[Test]
    public function it_exports_the_document_to_a_custom_path(): void
    {
        $path = $this->output . '/custom/openapi.json';

        $this->artisan('api-docs:export ' . $path)->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertSame('Example API', json_decode(File::get($path), true)['info']['title']);
    }

    #[Test]
    public function it_can_print_the_document_instead_of_writing_it(): void
    {
        $this->artisan('api-docs:export --print')
            ->assertSuccessful()
            ->expectsOutputToContain('"openapi": "3.1.0"');
    }

    #[Test]
    public function it_exports_yaml_when_asked(): void
    {
        $path = $this->output . '/openapi.yaml';

        $this->artisan('api-docs:export ' . $path . ' --format=yaml')->assertSuccessful();

        $contents = File::get($path);

        $this->assertStringContainsString('openapi: 3.1.0', $contents);
        $this->assertStringContainsString('/api/users:', $contents);
    }

    #[Test]
    public function it_clears_the_cached_documentation(): void
    {
        $this->withConfig(['api-docs.cache.enabled' => true]);

        $generator = $this->app->make(\Cofa\ApiDocs\DocumentationGenerator::class);
        $generator->spec();

        $this->assertTrue(cache()->has('api-docs.documentation'));

        $this->artisan('api-docs:clear')->assertSuccessful();

        $this->assertFalse(cache()->has('api-docs.documentation'));
    }
}
