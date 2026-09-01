<?php

namespace Cofa\ApiDocs\History;

use Cofa\ApiDocs\OpenApi\Operation;
use Cofa\ApiDocs\OpenApi\ResponseView;
use Cofa\ApiDocs\OpenApi\SchemaObject;
use Cofa\ApiDocs\OpenApi\Spec;
use Illuminate\Support\Str;

/**
 * Compares two OpenAPI documents and describes, in plain English, what changed
 * about every endpoint.
 */
class SpecDiffer
{
    /** @return array<int, OperationChange> */
    public function diff(Spec $old, Spec $new): array
    {
        $before = $this->index($old);
        $after = $this->index($new);

        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys, SORT_NATURAL);

        $result = [];

        foreach ($keys as $key) {
            $previous = $before[$key] ?? null;
            $current = $after[$key] ?? null;

            if ($previous === null && $current !== null) {
                $result[] = new OperationChange(
                    Change::ADDED,
                    $current->method,
                    $current->path,
                    [Change::added('endpoint', 'endpoint', 'Endpoint added')],
                    $current->group(),
                    $current->summary(),
                );

                continue;
            }

            if ($current === null && $previous !== null) {
                $result[] = new OperationChange(
                    Change::REMOVED,
                    $previous->method,
                    $previous->path,
                    [Change::removed('endpoint', 'endpoint', 'Endpoint removed')],
                    $previous->group(),
                    $previous->summary(),
                );

                continue;
            }

            if ($previous === null || $current === null) {
                continue;
            }

            $changes = $this->compare($previous, $current);

            if ($changes !== []) {
                $result[] = new OperationChange(
                    Change::MODIFIED,
                    $current->method,
                    $current->path,
                    $changes,
                    $current->group(),
                    $current->summary(),
                );
            }
        }

        return $result;
    }

    /** @return array<string, Operation> */
    protected function index(Spec $spec): array
    {
        $index = [];

        foreach ($spec->operations() as $operation) {
            $index[$operation->method . ' ' . $operation->path] = $operation;
        }

        return $index;
    }

    /** @return array<int, Change> */
    public function compare(Operation $old, Operation $new): array
    {
        return array_merge(
            $this->textChanges($old, $new),
            $this->flagChanges($old, $new),
            $this->parameterChanges($old, $new),
            $this->headerChanges($old, $new),
            $this->bodyChanges($old, $new),
            $this->responseChanges($old, $new),
        );
    }

    /** @return array<int, Change> */
    protected function textChanges(Operation $old, Operation $new): array
    {
        $changes = [];

        if ($old->summary() !== $new->summary()) {
            $changes[] = Change::modified(
                'summary',
                'summary',
                'Summary changed to "' . $this->truncate($new->summary()) . '"',
                $old->summary(),
                $new->summary(),
            );
        }

        if (trim($old->description()) !== trim($new->description())) {
            $changes[] = Change::modified(
                'description',
                'description',
                $new->description() === '' ? 'Description removed' : 'Description updated',
                $old->description(),
                $new->description(),
            );
        }

        if ($old->group() !== $new->group()) {
            $changes[] = Change::modified(
                'group',
                'group',
                'Moved from ' . $old->group() . ' to ' . $new->group(),
                $old->group(),
                $new->group(),
            );
        }

        return $changes;
    }

    /** @return array<int, Change> */
    protected function flagChanges(Operation $old, Operation $new): array
    {
        $changes = [];

        if ($old->isDeprecated() !== $new->isDeprecated()) {
            $changes[] = Change::modified(
                'deprecation',
                'deprecated',
                $new->isDeprecated() ? 'Marked deprecated' : 'No longer deprecated',
                $old->isDeprecated(),
                $new->isDeprecated(),
            );
        }

        if ($old->isAuthenticated() !== $new->isAuthenticated()) {
            $changes[] = Change::modified(
                'auth',
                'auth',
                $new->isAuthenticated() ? 'Now requires authentication' : 'No longer requires authentication',
                $old->isAuthenticated(),
                $new->isAuthenticated(),
            );
        } elseif ($old->securitySchemes() !== $new->securitySchemes() && $new->isAuthenticated()) {
            $changes[] = Change::modified(
                'auth',
                'auth.scheme',
                'Security scheme changed to ' . implode(', ', $new->securitySchemes()),
                $old->securitySchemes(),
                $new->securitySchemes(),
            );
        }

        return $changes;
    }

    /** @return array<int, Change> */
    protected function parameterChanges(Operation $old, Operation $new): array
    {
        $changes = [];

        foreach (['path', 'query'] as $in) {
            $before = $this->parameterMap($old, $in);
            $after = $this->parameterMap($new, $in);

            $changes = array_merge($changes, $this->compareFieldMaps(
                $before,
                $after,
                'parameter',
                $in,
                $in . ' parameter',
            ));
        }

        return $changes;
    }

    /** @return array<int, Change> */
    protected function headerChanges(Operation $old, Operation $new): array
    {
        $before = [];
        $after = [];

        foreach ($old->headers() as $header) {
            $before[$header['name']] = ['type' => 'string', 'required' => $header['required'], 'description' => $header['description']];
        }

        foreach ($new->headers() as $header) {
            $after[$header['name']] = ['type' => 'string', 'required' => $header['required'], 'description' => $header['description']];
        }

        return $this->compareFieldMaps($before, $after, 'header', 'header', 'header');
    }

    /** @return array<int, Change> */
    protected function bodyChanges(Operation $old, Operation $new): array
    {
        $changes = [];

        $oldType = $old->requestMediaType();
        $newType = $new->requestMediaType();

        if ($oldType !== $newType) {
            if ($oldType === null) {
                $changes[] = Change::added('body', 'body', 'Request body added (' . $newType . ')', $newType);
            } elseif ($newType === null) {
                $changes[] = Change::removed('body', 'body', 'Request body removed', $oldType);

                return $changes;
            } else {
                $changes[] = Change::modified('body', 'body:media', 'Request body is now ' . $newType, $oldType, $newType);
            }
        }

        return array_merge($changes, $this->compareFieldMaps(
            $this->fieldMap($old->requestSchema()),
            $this->fieldMap($new->requestSchema()),
            'body',
            'body',
            'body field',
        ));
    }

    /** @return array<int, Change> */
    protected function responseChanges(Operation $old, Operation $new): array
    {
        $before = $this->responseMap($old);
        $after = $this->responseMap($new);
        $changes = [];

        foreach ($after as $status => $response) {
            if (! isset($before[$status])) {
                $changes[] = Change::added('response', 'response.' . $status, 'Response ' . $status . ' added');
            }
        }

        foreach ($before as $status => $response) {
            if (! isset($after[$status])) {
                $changes[] = Change::removed('response', 'response.' . $status, 'Response ' . $status . ' removed');
            }
        }

        foreach ($after as $status => $response) {
            if (! isset($before[$status])) {
                continue;
            }

            $previous = $before[$status];

            if ($previous['description'] !== $response['description']) {
                $changes[] = Change::modified(
                    'response',
                    'response.' . $status . ':description',
                    'Response ' . $status . ' description updated',
                    $previous['description'],
                    $response['description'],
                );
            }

            $changes = array_merge($changes, $this->compareFieldMaps(
                $previous['fields'],
                $response['fields'],
                'response',
                'response.' . $status,
                $status . ' response field',
            ));
        }

        return $changes;
    }

    /**
     * @param  array<string, array{type: string, required: bool, description: string}>  $before
     * @param  array<string, array{type: string, required: bool, description: string}>  $after
     * @return array<int, Change>
     */
    protected function compareFieldMaps(array $before, array $after, string $category, string $prefix, string $noun): array
    {
        $changes = [];

        foreach ($after as $name => $field) {
            if (isset($before[$name])) {
                continue;
            }

            $changes[] = Change::added(
                $category,
                $prefix . '.' . $name,
                'Added ' . $noun . ' `' . $name . '`' . ($field['required'] ? ' (required)' : ''),
                $field,
            );
        }

        foreach ($before as $name => $field) {
            if (! isset($after[$name])) {
                $changes[] = Change::removed($category, $prefix . '.' . $name, 'Removed ' . $noun . ' `' . $name . '`', $field);
            }
        }

        foreach ($after as $name => $field) {
            if (! isset($before[$name])) {
                continue;
            }

            $previous = $before[$name];

            if ($previous['type'] !== $field['type']) {
                $changes[] = Change::modified(
                    $category,
                    $prefix . '.' . $name . ':type',
                    Str::ucfirst($noun) . ' `' . $name . '` changed from ' . $previous['type'] . ' to ' . $field['type'],
                    $previous['type'],
                    $field['type'],
                );
            }

            if ($previous['required'] !== $field['required']) {
                $changes[] = Change::modified(
                    $category,
                    $prefix . '.' . $name . ':required',
                    Str::ucfirst($noun) . ' `' . $name . '` is now ' . ($field['required'] ? 'required' : 'optional'),
                    $previous['required'],
                    $field['required'],
                );
            }

            if (($previous['enum'] ?? []) !== ($field['enum'] ?? [])) {
                $changes[] = Change::modified(
                    $category,
                    $prefix . '.' . $name . ':enum',
                    Str::ucfirst($noun) . ' `' . $name . '` allowed values changed to ' . $this->truncate(implode(', ', array_map('strval', $field['enum'] ?? []))),
                    $previous['enum'] ?? [],
                    $field['enum'] ?? [],
                );
            }

            if (trim($previous['description']) !== trim($field['description'])) {
                $changes[] = Change::modified(
                    $category,
                    $prefix . '.' . $name . ':description',
                    Str::ucfirst($noun) . ' `' . $name . '` description updated',
                    $previous['description'],
                    $field['description'],
                );
            }
        }

        return $changes;
    }

    /** @return array<string, array{type: string, required: bool, description: string, enum: array}> */
    protected function parameterMap(Operation $operation, string $in): array
    {
        $map = [];

        foreach ($operation->parameters($in) as $parameter) {
            /** @var SchemaObject $schema */
            $schema = $parameter['schema_object'];
            $name = (string) ($parameter['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $map[$name] = [
                'type' => $schema->type(),
                'required' => (bool) ($parameter['required'] ?? false),
                'description' => (string) ($parameter['description'] ?? $schema->description()),
                'enum' => $schema->enum(),
            ];
        }

        return $map;
    }

    /** @return array<string, array{type: string, required: bool, description: string, enum: array}> */
    protected function fieldMap(?SchemaObject $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $map = [];

        foreach ($schema->rows() as $row) {
            /** @var SchemaObject $field */
            $field = $row['schema'];

            $map[$row['path']] = [
                'type' => $field->type(),
                'required' => (bool) $row['required'],
                'description' => $field->description(),
                'enum' => $field->enum(),
            ];
        }

        return $map;
    }

    /** @return array<string, array{description: string, fields: array<string, mixed>}> */
    protected function responseMap(Operation $operation): array
    {
        $map = [];

        foreach ($operation->responses() as $response) {
            /** @var ResponseView $response */
            $map[$response->status] = [
                'description' => $response->description(),
                'fields' => $this->fieldMap($response->schema()),
            ];
        }

        return $map;
    }

    protected function truncate(string $value, int $length = 60): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $value) ?? $value), $length);
    }
}
