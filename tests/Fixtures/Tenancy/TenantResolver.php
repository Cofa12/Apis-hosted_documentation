<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Tenancy;

/**
 * The invokable-class form of the tenancy resolver, which is what a project
 * has to use when its configuration is cached.
 */
class TenantResolver
{
    public function __invoke(): ?string
    {
        return CurrentTenant::$key;
    }
}
