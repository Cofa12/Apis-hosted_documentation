<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Tenancy;

use Closure;

/** Stands in for an application middleware that reads the tenant from a header. */
class IdentifyTenant
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
