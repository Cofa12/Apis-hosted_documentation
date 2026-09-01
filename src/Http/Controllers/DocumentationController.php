<?php

namespace Cofa\ApiDocs\Http\Controllers;

use Cofa\ApiDocs\DocumentationGenerator;
use Cofa\ApiDocs\History\HistoryStore;
use Cofa\ApiDocs\OpenApi\CodeSampleGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocumentationController
{
    public function __construct(
        protected DocumentationGenerator $generator,
        protected HistoryStore $history,
    ) {
    }

    /** The rendered Blade documentation. */
    public function index(Request $request): Response
    {
        $config = $this->generator->config();
        $spec = $this->generator->spec(fresh: $request->boolean('fresh'));

        $html = view('api-docs::documentation', [
            'spec' => $spec,
            'ui' => (array) data_get($config, 'ui', []),
            'samples' => new CodeSampleGenerator((array) data_get($config, 'code_samples', ['curl'])),
            'baseUrl' => $this->baseUrl($config, $spec->baseUrl()),
            'specUrl' => $this->specUrl($config),
            'history' => $this->history->load(),
            'historyOptions' => (array) data_get($config, 'history', []),
        ])->render();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** The raw OpenAPI document, for external tooling. */
    public function spec(Request $request): JsonResponse
    {
        $document = $this->generator->spec(fresh: $request->boolean('fresh'))->toArray();

        return new JsonResponse($document, 200, [
            'Content-Disposition' => 'inline; filename="openapi.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $config */
    protected function baseUrl(array $config, string $fallback): string
    {
        $configured = (string) data_get($config, 'base_url', '');

        return rtrim($configured !== '' ? $configured : $fallback, '/');
    }

    /** @param array<string, mixed> $config */
    protected function specUrl(array $config): ?string
    {
        $path = trim((string) data_get($config, 'serve.path', 'api/documentation'), '/');

        return $path === '' ? null : url($path . '.json');
    }
}
