<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Controllers;

use Cofa\ApiDocs\Attributes\ApiDoc;
use Cofa\ApiDocs\Attributes\ApiGroup;
use Cofa\ApiDocs\Attributes\ApiHeader;
use Cofa\ApiDocs\Attributes\ApiParam;
use Cofa\ApiDocs\Attributes\ApiResponse;
use Cofa\ApiDocs\Attributes\Unauthenticated;
use Illuminate\Http\JsonResponse;

/**
 * A controller that documents the same action twice, and disagrees with
 * itself every way it can.
 */
class ConflictController
{
    /**
     * Docblock summary
     *
     * @group Docblock Group
     *
     * @authenticated
     *
     * @bodyParam email string required The email, from the docblock.
     * @bodyParam nickname string The nickname, from the docblock. Example: doc-nickname
     * @queryParam notify boolean Whether to notify.
     * @header X-Tenant docblock-value  The tenant, from the docblock.
     *
     * @response 200 {"from": "docblock"}
     */
    #[ApiGroup('Attribute Group')]
    #[ApiDoc(summary: 'Attribute summary')]
    #[Unauthenticated]
    #[ApiParam(name: 'email', required: false)]
    #[ApiParam(name: 'nickname')]
    #[ApiParam(name: 'notify', type: 'string', in: 'query')]
    #[ApiHeader(name: 'X-Tenant', value: 'attribute-value')]
    #[ApiResponse(status: 200, content: ['from' => 'attribute'])]
    public function update(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Agreeing on both sides
     *
     * @bodyParam email string required The email.
     */
    #[ApiParam(name: 'email', type: 'string', required: true, description: 'The email.')]
    public function agree(): JsonResponse
    {
        return response()->json([]);
    }
}
