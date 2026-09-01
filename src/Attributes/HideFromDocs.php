<?php

namespace Cofa\ApiDocs\Attributes;

use Attribute;

/** Excludes the endpoint (or the whole controller) from the documentation. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class HideFromDocs
{
}
