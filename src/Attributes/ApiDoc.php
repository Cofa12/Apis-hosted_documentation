<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Overrides the summary, description and OpenAPI metadata of an endpoint. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class ApiDoc
{
    /** @param array<int, string> $tags */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public ?string $operationId = null,
        public bool $deprecated = false,
        public array $tags = [],
    ) {
    }
}
