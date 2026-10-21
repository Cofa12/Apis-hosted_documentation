<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Tests\Fixtures\Tenancy\IdentifyTenant;
use Cofa\ApiDocs\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class TenantHeaderTest extends TestCase
{
    protected function registerTenantRoutes(): void
    {
        Route::aliasMiddleware('tenant.identify', IdentifyTenant::class);

        Route::middleware(['api', 'tenant.identify'])->prefix('api')->group(function () {
            Route::get('lessons', fn () => []);
        });

        Route::middleware(['api', IdentifyTenant::class])->prefix('api')->group(function () {
            Route::get('videos', fn () => []);
        });
    }

    protected function header(Endpoint $endpoint, string $name): ?HeaderParam
    {
        foreach ($endpoint->headers as $header) {
            if (strcasecmp($header->name, $name) === 0) {
                return $header;
            }
        }

        return null;
    }

    #[Test]
    public function no_tenant_header_is_added_until_one_is_configured(): void
    {
        $this->registerTenantRoutes();

        $this->assertNull($this->header($this->endpoint('GET', 'api/lessons'), 'X-Tenant'));
    }

    #[Test]
    public function the_tenant_header_is_added_to_every_route_when_no_middleware_is_listed(): void
    {
        $this->withConfig(['api-docs.tenancy.header.name' => 'X-Tenant']);

        $header = $this->header($this->endpoint('GET', 'api/health'), 'X-Tenant');

        $this->assertNotNull($header);
        $this->assertTrue($header->required);
        $this->assertSame('your-tenant', $header->value);
    }

    #[Test]
    public function the_tenant_header_is_limited_to_routes_behind_the_listed_middleware(): void
    {
        $this->withConfig([
            'api-docs.tenancy.header.name' => 'X-Tenant',
            'api-docs.tenancy.header.value' => 'acme',
            'api-docs.tenancy.header.middleware' => ['tenant.identify'],
        ]);
        $this->registerTenantRoutes();

        $this->assertSame('acme', $this->header($this->endpoint('GET', 'api/lessons'), 'X-Tenant')?->value);
        $this->assertNotNull($this->header($this->endpoint('GET', 'api/videos'), 'X-Tenant'));
        $this->assertNull($this->header($this->endpoint('GET', 'api/health'), 'X-Tenant'));
    }

    #[Test]
    public function middleware_can_be_listed_by_class_name_or_basename(): void
    {
        foreach ([IdentifyTenant::class, 'IdentifyTenant'] as $needle) {
            $this->withConfig([
                'api-docs.tenancy.header.name' => 'X-Tenant',
                'api-docs.tenancy.header.middleware' => [$needle],
            ]);
            $this->registerTenantRoutes();

            $this->assertNotNull($this->header($this->endpoint('GET', 'api/lessons'), 'X-Tenant'), $needle);
            $this->assertNull($this->header($this->endpoint('GET', 'api/health'), 'X-Tenant'), $needle);
        }
    }

    #[Test]
    public function a_header_documented_on_the_endpoint_wins_over_the_configured_one(): void
    {
        $this->withConfig(['api-docs.tenancy.header.name' => 'x-tenant']);

        $headers = array_filter(
            $this->endpoint('POST', 'api/reports/{report}')->headers,
            fn (HeaderParam $header) => strcasecmp($header->name, 'X-Tenant') === 0
        );

        $this->assertCount(1, $headers);
        $this->assertSame('acme', array_values($headers)[0]->value);
    }

    #[Test]
    public function the_tenant_header_reaches_the_try_it_console(): void
    {
        $this->withConfig(['api-docs.tenancy.header.name' => 'X-Tenant']);

        $this->assertStringContainsString('data-header-name value="X-Tenant"', $this->get('/api/documentation')->getContent());
    }
}
