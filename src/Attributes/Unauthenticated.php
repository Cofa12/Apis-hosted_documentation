<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Marks the endpoint as public even though its middleware suggests otherwise. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Unauthenticated
{
}
