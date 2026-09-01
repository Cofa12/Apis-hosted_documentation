<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\DocumentationGenerator;
use Cofa\ApiDocs\Tests\Fixtures\Cache\BrokenStore;
use Cofa\ApiDocs\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reported from a multi-tenant project: `api-docs:generate` finished its work
 * and then died with "no such table: cache" while flushing a cache the project
 * had never even enabled.
 */
class UnreachableCacheTest extends TestCase
{
    protected string $output = '';

    protected BrokenStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir() . '/api-docs-cache-' . bin2hex(random_bytes(4));

        $this->withConfig([
            'api-docs.output.views_path' => $this->output . '/views',
            'api-docs.output.spec_file' => $this->output . '/views/openapi.json',
            'api-docs.history.path' => $this->output . '/history.json',
        ]);

        $this->breakTheCache();
    }

    protected function tearDown(): void
    {
        if ($this->output !== '' && File::isDirectory($this->output)) {
            File::deleteDirectory($this->output);
        }

        parent::tearDown();
    }

    protected function breakTheCache(): void
    {
        $this->store = new BrokenStore();

        $this->app->bind(CacheRepository::class, fn () => new Repository($this->store));
        $this->app->forgetInstance(DocumentationGenerator::class);
    }

    #[Test]
    public function generating_does_not_touch_the_cache_when_caching_is_off(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->assertSame(0, $this->store->calls, 'Nothing was cached, so nothing needs flushing.');
        $this->assertFileExists($this->output . '/views/openapi.json');
    }

    #[Test]
    public function an_unreachable_cache_does_not_fail_the_generate_command(): void
    {
        $this->withConfig(['api-docs.cache.enabled' => true]);
        $this->breakTheCache();

        $this->artisan('api-docs:generate')
            ->expectsOutputToContain('no such table: cache')
            ->assertSuccessful();

        $this->assertGreaterThan(0, $this->store->calls);
        $this->assertFileExists($this->output . '/views/openapi.json', 'The documentation is still written.');
    }

    #[Test]
    public function the_page_falls_back_to_a_live_scan_when_the_cache_is_unreachable(): void
    {
        $this->withConfig(['api-docs.cache.enabled' => true]);
        $this->breakTheCache();

        $generator = $this->app->make(DocumentationGenerator::class);
        $spec = $generator->spec();

        $this->assertSame('Example API', $spec->title());
        $this->assertGreaterThan(0, $spec->operationCount());
        $this->assertStringContainsString('no such table: cache', (string) $generator->cacheError());

        $this->get('/api/documentation')->assertOk();
        $this->getJson('/api/documentation.json')->assertOk();
    }

    #[Test]
    public function clearing_reports_an_unreachable_store_instead_of_throwing(): void
    {
        $this->artisan('api-docs:clear')
            ->expectsOutputToContain('The cache store could not be reached')
            ->assertSuccessful();
    }

    #[Test]
    public function clearing_fails_loudly_when_caching_is_actually_in_use(): void
    {
        $this->withConfig(['api-docs.cache.enabled' => true]);
        $this->breakTheCache();

        $this->artisan('api-docs:clear')
            ->expectsOutputToContain('The cache store could not be reached')
            ->assertFailed();
    }

    #[Test]
    public function the_documentation_cache_can_be_pointed_at_a_different_store(): void
    {
        // The default store is broken, but an explicit one is not.
        $this->withConfig([
            'api-docs.cache.enabled' => true,
            'api-docs.cache.store' => 'array',
        ]);

        $generator = $this->app->make(DocumentationGenerator::class);

        $this->assertSame('Example API', $generator->spec()->title());
        $this->assertNull($generator->cacheError());
        $this->assertTrue(cache()->store('array')->has('api-docs.documentation'));
    }

    #[Test]
    public function an_unknown_store_name_does_not_break_anything(): void
    {
        $this->withConfig([
            'api-docs.cache.enabled' => true,
            'api-docs.cache.store' => 'not-a-real-store',
        ]);

        $this->assertSame('Example API', $this->app->make(DocumentationGenerator::class)->spec()->title());
        $this->artisan('api-docs:generate')->assertSuccessful();
    }

    #[Test]
    public function a_healthy_cache_still_works(): void
    {
        $this->withConfig(['api-docs.cache.enabled' => true]);

        $generator = $this->app->make(DocumentationGenerator::class);

        $this->assertSame('Example API', $generator->spec()->title());
        $this->assertNull($generator->cacheError());
        $this->assertTrue(cache()->has('api-docs.documentation'));

        $this->artisan('api-docs:clear')->assertSuccessful();
        $this->assertFalse(cache()->has('api-docs.documentation'));
    }
}
