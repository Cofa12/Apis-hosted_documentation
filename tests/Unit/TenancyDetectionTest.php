<?php

/**
 * The tenancy packages are detected through globals they define. Rather than
 * pull either package in as a dev dependency, this file stands in for the
 * parts the detection actually touches, so both branches stay covered.
 */

namespace Spatie\Multitenancy\Models {
    if (! class_exists(Tenant::class, false)) {
        define('API_DOCS_FAKE_SPATIE', true);

        class Tenant
        {
            public static ?self $current = null;

            public function __construct(public string|int|null $id = null)
            {
            }

            public static function current(): ?self
            {
                return self::$current;
            }

            public function getKey(): string|int|null
            {
                return $this->id;
            }
        }
    }
}

namespace {
    if (! function_exists('tenant')) {
        define('API_DOCS_FAKE_STANCL', true);

        function tenant()
        {
            return $GLOBALS['api_docs_fake_tenant'] ?? null;
        }
    }
}

namespace Cofa\ApiDocs\Tests\Unit {

    use Cofa\ApiDocs\Tenancy\Tenancy;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;
    use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

    class TenancyDetectionTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['api_docs_fake_tenant']);
            SpatieTenant::$current = null;
        }

        protected function tenancy(array $tenancy = []): Tenancy
        {
            return new Tenancy(['tenancy' => array_merge(['enabled' => true], $tenancy)]);
        }

        #[Test]
        public function it_reads_the_tenant_from_a_stancl_style_helper(): void
        {
            if (! defined('API_DOCS_FAKE_STANCL')) {
                $this->markTestSkipped('A real tenant() helper is installed.');
            }

            $GLOBALS['api_docs_fake_tenant'] = new class
            {
                public function getTenantKey(): string
                {
                    return 'acme';
                }
            };

            $this->assertSame('acme', $this->tenancy()->key());
        }

        #[Test]
        public function no_active_stancl_tenant_means_the_central_context(): void
        {
            if (! defined('API_DOCS_FAKE_STANCL')) {
                $this->markTestSkipped('A real tenant() helper is installed.');
            }

            $this->assertNull($this->tenancy()->key());
            $this->assertSame('central', $this->tenancy()->id());
        }

        #[Test]
        public function it_reads_the_tenant_from_a_spatie_style_model(): void
        {
            if (! defined('API_DOCS_FAKE_SPATIE')) {
                $this->markTestSkipped('A real Spatie tenant model is installed.');
            }

            SpatieTenant::$current = new SpatieTenant(7);

            $this->assertSame('7', $this->tenancy()->key());
        }

        #[Test]
        public function an_explicit_resolver_beats_both_packages(): void
        {
            $GLOBALS['api_docs_fake_tenant'] = new class
            {
                public function getTenantKey(): string
                {
                    return 'from-stancl';
                }
            };

            $this->assertSame('explicit', $this->tenancy(['resolver' => fn () => 'explicit'])->key());
        }

        #[Test]
        public function the_host_strategy_is_only_used_when_asked_for(): void
        {
            // Without a request object the host strategy simply yields nothing.
            $this->assertNull($this->tenancy(['strategy' => 'host'])->key());
        }
    }
}
