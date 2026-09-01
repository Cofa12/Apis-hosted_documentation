<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Controllers;

use Cofa\ApiDocs\Tests\Fixtures\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * @group Reports
 *
 * Scheduled and ad-hoc reporting.
 */
class ReportController
{
    /**
     * Build a report
     *
     * Queues a report and returns the recipients it will be sent to.
     *
     * @authenticated
     *
     * @header X-Tenant acme  The tenant the report belongs to.
     * @urlParam report string required The report slug. Example: monthly-revenue
     * @queryParam preview boolean Render without queueing. Example: true
     * @bodyParam recipients string[] required Who receives the report. Example: ["ops@example.com"]
     * @bodyParam options object The renderer options.
     *
     * @apiResource 202 Cofa\ApiDocs\Tests\Fixtures\Resources\UserResource
     * @response 409 Another run is already in progress.
     */
    public function store(string $report): JsonResponse
    {
        return response()->json([]);
    }
}
