<?php

namespace Cofa\ApiDocs\Extractors;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\ResponseExample;
use Cofa\ApiDocs\Extractors\Contracts\Extractor;
use Cofa\ApiDocs\Scanning\RouteContext;
use Illuminate\Support\Str;

/**
 * Documents the failure paths Laravel produces on its own: an unauthenticated
 * request, a failed authorization, a missing bound model, a rejected payload
 * and a throttled client.
 */
class ErrorResponseExtractor implements Extractor
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = [])
    {
    }

    public function extract(Endpoint $endpoint, RouteContext $context): void
    {
        if (! data_get($this->config, 'responses.include_errors', true)) {
            return;
        }

        if ($endpoint->authenticated) {
            $endpoint->addResponse(new ResponseExample(
                status: 401,
                content: ['message' => 'Unauthenticated.'],
                description: 'The request is missing a valid access token.',
            ), overwrite: false);
        }

        if ($this->hasAuthorization($endpoint, $context)) {
            $endpoint->addResponse(new ResponseExample(
                status: 403,
                content: ['message' => 'This action is unauthorized.'],
                description: 'The authenticated user may not perform this action.',
            ), overwrite: false);
        }

        if ($endpoint->urlParameters !== []) {
            $endpoint->addResponse(new ResponseExample(
                status: 404,
                content: ['message' => 'No query results for model.'],
                description: 'No resource matches the given identifier.',
            ), overwrite: false);
        }

        if (! empty($endpoint->meta['has_validation']) || $this->hasRequiredInput($endpoint)) {
            $endpoint->addResponse(new ResponseExample(
                status: 422,
                content: $this->validationBody($endpoint),
                description: 'The payload failed validation.',
            ), overwrite: false);
        }

        if ($this->isThrottled($endpoint)) {
            $endpoint->addResponse(new ResponseExample(
                status: 429,
                content: ['message' => 'Too Many Attempts.'],
                description: 'The rate limit for this endpoint was exceeded.',
                headers: ['Retry-After' => '60', 'X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0'],
            ), overwrite: false);
        }
    }

    protected function hasAuthorization(Endpoint $endpoint, RouteContext $context): bool
    {
        foreach ($endpoint->middleware as $middleware) {
            if (Str::startsWith($middleware, ['can:', 'authorize', 'ability:', 'role:', 'permission:'])) {
                return true;
            }
        }

        $formRequest = $endpoint->meta['form_request'] ?? null;

        if (is_string($formRequest) && method_exists($formRequest, 'authorize')) {
            return true;
        }

        return false;
    }

    protected function hasRequiredInput(Endpoint $endpoint): bool
    {
        foreach (array_merge($endpoint->bodyParameters, $endpoint->queryParameters) as $parameter) {
            if ($parameter->required) {
                return true;
            }
        }

        return false;
    }

    protected function isThrottled(Endpoint $endpoint): bool
    {
        foreach ($endpoint->middleware as $middleware) {
            if (Str::startsWith($middleware, 'throttle')) {
                return true;
            }
        }

        return false;
    }

    /** Build a 422 body out of the endpoint's own required fields. */
    protected function validationBody(Endpoint $endpoint): array
    {
        $errors = [];

        foreach (array_merge($endpoint->bodyParameters, $endpoint->queryParameters) as $parameter) {
            if (! $parameter->required || count($errors) >= 2) {
                continue;
            }

            $label = str_replace('_', ' ', $parameter->name);
            $errors[$parameter->name] = ['The ' . $label . ' field is required.'];
        }

        if ($errors === []) {
            $errors = ['field' => ['The field is invalid.']];
        }

        return [
            'message' => $this->validationMessage($errors),
            'errors' => $errors,
        ];
    }

    /** @param array<string, array<int, string>> $errors */
    protected function validationMessage(array $errors): string
    {
        $first = reset($errors);
        $message = is_array($first) ? ($first[0] ?? 'The given data was invalid.') : 'The given data was invalid.';
        $extra = count($errors) - 1;

        return $extra > 0
            ? $message . ' (and ' . $extra . ' more error' . ($extra > 1 ? 's' : '') . ')'
            : $message;
    }
}
