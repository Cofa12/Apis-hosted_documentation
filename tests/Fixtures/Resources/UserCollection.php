<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A collection that does not override toArray(), so its shape has to come from
 * the model it is named after.
 */
class UserCollection extends ResourceCollection
{
}
