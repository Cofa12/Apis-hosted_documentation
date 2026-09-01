<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Tenancy;

/**
 * A stand-in for whatever a project uses to track the active tenant.
 */
class CurrentTenant
{
    public static ?string $key = null;

    public static function set(?string $key): void
    {
        self::$key = $key;
    }
}
