<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\DocumentationGenerator;
use Cofa\ApiDocs\History\HistoryStore;
use Cofa\ApiDocs\Tests\Fixtures\Tenancy\CurrentTenant;
use Cofa\ApiDocs\Tests\Fixtures\Tenancy\TenantResolver;
use Cofa\ApiDocs\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class MultiTenancyTest extends TestCase
{
    protected string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir() . '/api-docs-tenancy-' . bin2hex(random_bytes(4));

        $this->withConfig([
            'api-docs.tenancy.enabled' => true,
            'api-docs.tenancy.resolver' => TenantResolver::class,
            'api-docs.title' => '{tenant} API',
            'api-docs.base_url' => 'https://{tenant}.example.test',
            'api-docs.output.spec_file' => $this->output . '/{tenant}/openapi.json',
            'api-docs.output.views_path' => $this->output . '/{tenant}/views',
            'api-docs.history.path' => $this->output . '/{tenant}/history.json',
        ]);

        CurrentTenant::set('acme');
    }

    protected function tearDown(): void
    {
        CurrentTenant::set(null);

        if ($this->output !== '' && File::isDirectory($this->output)) {
            File::deleteDirectory($this->output);
        }

        parent::tearDown();
    }

    #[Test]
    public function the_generated_artefacts_land_in_the_tenants_own_directory(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->assertFileExists($this->output . '/acme/openapi.json');
        $this->assertFileExists($this->output . '/acme/history.json');
        $this->assertFileExists($this->output . '/acme/views/documentation.blade.php');
        $this->assertDirectoryDoesNotExist($this->output . '/central');
    }

    #[Test]
    public function each_tenant_gets_its_own_document_and_history(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        CurrentTenant::set('globex');

        // Only this tenant sees the extra endpoint.
        Route::middleware('api')->prefix('api')->group(function () {
            Route::get('globex-only', fn () => []);
        });

        $this->artisan('api-docs:generate')->assertSuccessful();

        $acme = json_decode(File::get($this->output . '/acme/openapi.json'), true);
        $globex = json_decode(File::get($this->output . '/globex/openapi.json'), true);

        $this->assertArrayNotHasKey('/api/globex-only', $acme['paths']);
        $this->assertArrayHasKey('/api/globex-only', $globex['paths']);

        // Two independent timelines, each starting from its own baseline.
        $acmeHistory = json_decode(File::get($this->output . '/acme/history.json'), true);
        $globexHistory = json_decode(File::get($this->output . '/globex/history.json'), true);

        $this->assertTrue($acmeHistory['revisions'][0]['initial']);
        $this->assertTrue($globexHistory['revisions'][0]['initial']);
        $this->assertCount(1, $globexHistory['revisions'], 'The other tenant is not part of this timeline.');
    }

    #[Test]
    public function the_placeholder_is_resolved_in_the_document_itself(): void
    {
        $spec = $this->app->make(DocumentationGenerator::class)->generate()->toArray();

        $this->assertSame('acme API', $spec['info']['title']);
        $this->assertSame('https://acme.example.test', $spec['servers'][0]['url']);
    }

    #[Test]
    public function switching_tenant_switches_the_document_without_a_rebuild(): void
    {
        $generator = $this->app->make(DocumentationGenerator::class);

        $this->assertSame('acme API', $generator->generate()->toArray()['info']['title']);

        CurrentTenant::set('globex');

        $this->assertSame(
            'globex API',
            $generator->generate()->toArray()['info']['title'],
            'The tenant is resolved on every read, not cached at boot.'
        );
    }

    #[Test]
    public function the_history_store_follows_the_tenant(): void
    {
        $store = $this->app->make(HistoryStore::class);

        $this->assertSame($this->output . '/acme/history.json', $store->path());

        CurrentTenant::set('globex');

        $this->assertSame($this->output . '/globex/history.json', $store->path());
    }

    #[Test]
    public function the_cached_document_is_scoped_per_tenant(): void
    {
        $this->withConfig([
            'api-docs.cache.enabled' => true,
            'api-docs.tenancy.enabled' => true,
            'api-docs.tenancy.resolver' => TenantResolver::class,
            'api-docs.title' => '{tenant} API',
        ]);

        CurrentTenant::set('acme');
        $generator = $this->app->make(DocumentationGenerator::class);
        $this->assertSame('acme API', $generator->spec()->title());

        CurrentTenant::set('globex');
        $this->assertSame('globex API', $generator->spec()->title(), 'A shared cache would leak acme into globex.');

        $this->assertTrue(cache()->has('api-docs.documentation.acme'));
        $this->assertTrue(cache()->has('api-docs.documentation.globex'));
        $this->assertFalse(cache()->has('api-docs.documentation'));
    }

    #[Test]
    public function clearing_the_cache_clears_the_current_tenant_only(): void
    {
        $this->withConfig([
            'api-docs.cache.enabled' => true,
            'api-docs.tenancy.enabled' => true,
            'api-docs.tenancy.resolver' => TenantResolver::class,
        ]);

        CurrentTenant::set('acme');
        $this->app->make(DocumentationGenerator::class)->spec();

        CurrentTenant::set('globex');
        $this->app->make(DocumentationGenerator::class)->spec();

        CurrentTenant::set('acme');
        $this->artisan('api-docs:clear')->assertSuccessful();

        $this->assertFalse(cache()->has('api-docs.documentation.acme'));
        $this->assertTrue(cache()->has('api-docs.documentation.globex'));
    }

    #[Test]
    public function the_page_points_the_samples_at_the_host_it_is_served_from(): void
    {
        $html = $this->get('http://globex.example.test/api/documentation')->assertOk()->getContent();

        $this->assertStringContainsString('http://globex.example.test/api/users', $html);
        $this->assertStringNotContainsString('https://acme.example.test/api/users', $html);
    }

    #[Test]
    public function following_the_request_host_can_be_switched_off(): void
    {
        $this->withConfig(['api-docs.tenancy.follow_request_host' => false]);
        CurrentTenant::set('acme');

        $html = $this->get('http://globex.example.test/api/documentation')->assertOk()->getContent();

        $this->assertStringContainsString('https://acme.example.test/api/users', $html);
    }

    #[Test]
    public function a_tenant_key_cannot_escape_its_directory(): void
    {
        CurrentTenant::set('../../../etc');

        $this->artisan('api-docs:generate --no-views')->assertSuccessful();

        $this->assertFileExists($this->output . '/etc/openapi.json');
        $this->assertFileDoesNotExist(dirname($this->output, 3) . '/etc/openapi.json');
    }

    #[Test]
    public function it_falls_back_to_the_central_context_when_no_tenant_is_active(): void
    {
        CurrentTenant::set(null);

        $this->artisan('api-docs:generate --no-views')->assertSuccessful();

        $this->assertFileExists($this->output . '/central/openapi.json');
        $this->assertSame(
            'central API',
            json_decode(File::get($this->output . '/central/openapi.json'), true)['info']['title']
        );
    }
}
