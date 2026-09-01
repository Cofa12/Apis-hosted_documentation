<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Controllers;

use Cofa\ApiDocs\Attributes\ApiDoc;
use Cofa\ApiDocs\Attributes\ApiGroup;
use Illuminate\Http\JsonResponse;

#[ApiGroup(name: 'System', description: 'Operational endpoints.')]
class HealthController
{
    /**
     * @response 200 {"status": "ok", "uptime": 128456}
     */
    #[ApiDoc(summary: 'Health check', description: 'Reports whether the API is reachable.')]
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
