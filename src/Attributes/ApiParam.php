<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Documents one input field: body by default, or query/path. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiParam
{
    /** @param array<int, string> $enum */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public string $description = '',
        public mixed $example = null,
        public string $in = 'body',
        public array $enum = [],
    ) {
    }
}
