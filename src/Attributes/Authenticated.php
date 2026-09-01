<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Marks the endpoint as requiring authentication. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Authenticated
{
    public function __construct(public ?string $scheme = null)
    {
    }
}
