<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * @group Legacy
 */
class LegacyController
{
    /**
     * Old export
     *
     * @deprecated Use the reports endpoint instead.
     *
     * @bodyParam format string required The export format. Example: csv
     * @response 202 {"queued": true}
     */
    public function export(): JsonResponse
    {
        return response()->json(['queued' => true], 202);
    }
}
