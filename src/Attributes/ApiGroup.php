<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Places the endpoint (or every endpoint of a controller) in a named group. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ApiGroup
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {
    }
}
