<?php

namespace Cofa\ApiDocs\Extractors\Contracts;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Scanning\RouteContext;

interface Extractor
{
    /**
     * Enrich the endpoint with whatever this extractor can learn about it.
     * Extractors run in order and must never throw: a project that cannot be
     * fully understood should still produce documentation for everything else.
     */
    public function extract(Endpoint $endpoint, RouteContext $context): void;
}
