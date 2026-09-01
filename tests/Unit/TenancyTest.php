<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Tenancy\Tenancy;
use Cofa\ApiDocs\Tests\Fixtures\Tenancy\CurrentTenant;
use Cofa\ApiDocs\Tests\Fixtures\Tenancy\TenantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenancyTest extends TestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::set(null);
    }

    protected function tenancy(array $tenancy = [], array $extra = []): Tenancy
    {
        return new Tenancy(array_merge(['tenancy' => array_merge(['enabled' => true], $tenancy)], $extra));
    }

    #[Test]
    public function it_is_off_by_default(): void
    {
        $tenancy = new Tenancy([]);

        $this->assertFalse($tenancy->enabled());
        $this->assertNull($tenancy->key());
        $this->assertTrue($tenancy->isCentral());
    }

    #[Test]
    public function a_disabled_tenancy_still_resolves_the_placeholder(): void
    {
        // Leaving a literal {tenant} in a filesystem path would be worse.
        $this->assertSame('docs/central/openapi.json', (new Tenancy([]))->apply('docs/{tenant}/openapi.json'));
    }

    #[Test]
    public function a_closure_resolver_decides_the_tenant(): void
    {
        $tenancy = $this->tenancy(['resolver' => fn () => 'acme']);

        $this->assertSame('acme', $tenancy->key());
        $this->assertSame('acme', $tenancy->id());
        $this->assertFalse($tenancy->isCentral());
    }

    #[Test]
    public function an_invokable_class_can_resolve_the_tenant(): void
    {
        CurrentTenant::set('globex');

        $this->assertSame('globex', $this->tenancy(['resolver' => TenantResolver::class])->key());
    }

    #[Test]
    public function a_resolver_returning_nothing_falls_back_to_the_central_key(): void
    {
        $tenancy = $this->tenancy(['resolver' => fn () => null, 'central_key' => 'shared']);

        $this->assertNull($tenancy->key());
        $this->assertSame('shared', $tenancy->id());
        $this->assertSame('docs/shared', $tenancy->apply('docs/{tenant}'));
    }

    #[Test]
    public function a_resolver_that_throws_does_not_break_the_documentation(): void
    {
        $tenancy = $this->tenancy(['resolver' => fn () => throw new \RuntimeException('tenancy not initialised')]);

        $this->assertNull($tenancy->key());
        $this->assertSame('central', $tenancy->id());
    }

    #[Test]
    public function an_integer_tenant_key_is_accepted(): void
    {
        $this->assertSame('42', $this->tenancy(['resolver' => fn () => 42])->key());
    }

    #[Test]
    #[DataProvider('unsafeKeys')]
    public function it_sanitises_keys_that_would_escape_a_path(string $key, string $expected): void
    {
        $this->assertSame($expected, $this->tenancy(['resolver' => fn () => $key])->key());
    }

    public static function unsafeKeys(): array
    {
        return [
            'traversal' => ['../../etc/passwd', 'etc-passwd'],
            'dot segments' => ['..', 'tenant'],
            'slashes' => ['acme/prod', 'acme-prod'],
            'spaces and case' => ['Acme Corp', 'acme-corp'],
            'host' => ['acme.example.com', 'acme.example.com'],
            'uuid' => ['9d5f2f9c-8f4e-4a51-b6d3-6c6c6d3f0a11', '9d5f2f9c-8f4e-4a51-b6d3-6c6c6d3f0a11'],
            'null bytes' => ["acme\0evil", 'acme-evil'],
        ];
    }

    #[Test]
    public function it_replaces_the_placeholder_everywhere_in_the_configuration(): void
    {
        $tenancy = $this->tenancy(['resolver' => fn () => 'acme']);

        $resolved = $tenancy->applyToConfig([
            'title' => '{tenant} API',
            'base_url' => 'https://{tenant}.example.com',
            'output' => ['spec_file' => 'docs/{tenant}/openapi.json'],
            'openapi' => ['servers' => [['url' => 'https://{tenant}.example.com/v1']]],
            'untouched' => 'plain value',
            'number' => 15,
        ]);

        $this->assertSame('acme API', $resolved['title']);
        $this->assertSame('https://acme.example.com', $resolved['base_url']);
        $this->assertSame('docs/acme/openapi.json', $resolved['output']['spec_file']);
        $this->assertSame('https://acme.example.com/v1', $resolved['openapi']['servers'][0]['url']);
        $this->assertSame('plain value', $resolved['untouched']);
        $this->assertSame(15, $resolved['number']);
    }

    #[Test]
    public function the_cache_key_is_scoped_so_tenants_cannot_read_each_other(): void
    {
        $tenancy = $this->tenancy(['resolver' => fn () => 'acme']);

        $this->assertSame('api-docs.documentation.acme', $tenancy->cacheKey('api-docs.documentation'));
        $this->assertSame('docs.acme.spec', $tenancy->cacheKey('docs.{tenant}.spec'), 'An explicit placeholder wins.');
    }

    #[Test]
    public function cache_scoping_can_be_turned_off(): void
    {
        $tenancy = $this->tenancy(['resolver' => fn () => 'acme', 'scope_cache' => false]);

        $this->assertSame('api-docs.documentation', $tenancy->cacheKey('api-docs.documentation'));
    }

    #[Test]
    public function a_disabled_tenancy_leaves_the_cache_key_alone(): void
    {
        $this->assertSame('api-docs.documentation', (new Tenancy([]))->cacheKey('api-docs.documentation'));
    }

    #[Test]
    public function following_the_request_host_needs_tenancy_to_be_enabled(): void
    {
        $this->assertFalse((new Tenancy([]))->followsRequestHost());
        $this->assertTrue($this->tenancy()->followsRequestHost());
        $this->assertFalse($this->tenancy(['follow_request_host' => false])->followsRequestHost());
    }

    #[Test]
    public function the_configuration_can_be_swapped_in_place(): void
    {
        $tenancy = new Tenancy([]);

        $this->assertFalse($tenancy->enabled());

        $tenancy->setConfig(['tenancy' => ['enabled' => true, 'resolver' => fn () => 'acme']]);

        $this->assertSame('acme', $tenancy->key());
    }
}
