<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Documents a request header. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiHeader
{
    public function __construct(
        public string $name,
        public string $value = '',
        public bool $required = false,
        public string $description = '',
    ) {
    }
}
