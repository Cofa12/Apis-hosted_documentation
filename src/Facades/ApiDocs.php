<?php

namespace Cofa\ApiDocs\Facades;

use Cofa\ApiDocs\DocumentationGenerator;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cofa\ApiDocs\OpenApi\Spec spec(bool $fresh = false)
 * @method static \Cofa\ApiDocs\OpenApi\Spec generate()
 * @method static array endpoints()
 * @method static array errors()
 * @method static void forgetCache()
 *
 * @see \Cofa\ApiDocs\DocumentationGenerator
 */
class ApiDocs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocumentationGenerator::class;
    }
}
