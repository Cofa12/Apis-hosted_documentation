<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/**
 * Documents one possible response. Either give the body directly, or point at
 * an API resource / model class and let the generator infer the shape.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiResponse
{
    public function __construct(
        public int $status = 200,
        public mixed $content = null,
        public string $description = '',
        public ?string $resource = null,
        public bool $collection = false,
        public string $contentType = 'application/json',
    ) {
    }
}
