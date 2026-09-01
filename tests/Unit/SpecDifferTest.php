<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\History\Change;
use Cofa\ApiDocs\History\OperationChange;
use Cofa\ApiDocs\History\SpecDiffer;
use Cofa\ApiDocs\OpenApi\Spec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SpecDifferTest extends TestCase
{
    protected SpecDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new SpecDiffer();
    }

    /** @param array<string, mixed> $operation */
    protected function spec(array $operation, string $path = '/users/{user}', string $method = 'put'): Spec
    {
        return Spec::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Demo', 'version' => '1.0.0'],
            'paths' => [$path => [$method => $operation + ['responses' => ['200' => ['description' => 'OK']]]]],
        ]);
    }

    /** @return array<int, string> */
    protected function summaries(Spec $old, Spec $new): array
    {
        $summaries = [];

        foreach ($this->differ->diff($old, $new) as $operation) {
            foreach ($operation->changes as $change) {
                $summaries[] = $change->summary;
            }
        }

        return $summaries;
    }

    #[Test]
    public function an_identical_document_produces_no_changes(): void
    {
        $spec = $this->spec(['summary' => 'Update user']);

        $this->assertSame([], $this->differ->diff($spec, $spec));
    }

    #[Test]
    public function it_reports_an_added_endpoint(): void
    {
        $old = Spec::fromArray(['paths' => []]);
        $new = $this->spec(['summary' => 'Update user']);

        $changes = $this->differ->diff($old, $new);

        $this->assertCount(1, $changes);
        $this->assertSame(Change::ADDED, $changes[0]->type);
        $this->assertSame('PUT', $changes[0]->method);
        $this->assertSame('/users/{user}', $changes[0]->path);
        $this->assertSame('Endpoint added', $changes[0]->changes[0]->summary);
        $this->assertFalse($changes[0]->isBreaking());
    }

    #[Test]
    public function it_reports_a_removed_endpoint_as_breaking(): void
    {
        $changes = $this->differ->diff($this->spec(['summary' => 'Update user']), Spec::fromArray(['paths' => []]));

        $this->assertSame(Change::REMOVED, $changes[0]->type);
        $this->assertTrue($changes[0]->isBreaking());
        $this->assertSame('Removed', $changes[0]->label());
    }

    #[Test]
    public function it_reports_text_changes(): void
    {
        $summaries = $this->summaries(
            $this->spec(['summary' => 'Update user', 'description' => 'Old text.', 'tags' => ['Users']]),
            $this->spec(['summary' => 'Edit user', 'description' => 'New text.', 'tags' => ['Accounts']]),
        );

        $this->assertContains('Summary changed to "Edit user"', $summaries);
        $this->assertContains('Description updated', $summaries);
        $this->assertContains('Moved from Users to Accounts', $summaries);
    }

    #[Test]
    public function it_reports_deprecation_and_authentication(): void
    {
        $summaries = $this->summaries(
            $this->spec(['security' => []]),
            $this->spec(['deprecated' => true, 'security' => [['bearerAuth' => []]]]),
        );

        $this->assertContains('Marked deprecated', $summaries);
        $this->assertContains('Now requires authentication', $summaries);

        $back = $this->summaries(
            $this->spec(['deprecated' => true, 'security' => [['bearerAuth' => []]]]),
            $this->spec(['security' => []]),
        );

        $this->assertContains('No longer deprecated', $back);
        $this->assertContains('No longer requires authentication', $back);
    }

    #[Test]
    public function requiring_authentication_is_breaking(): void
    {
        $changes = $this->differ->diff($this->spec(['security' => []]), $this->spec(['security' => [['bearerAuth' => []]]]));

        $this->assertTrue($changes[0]->isBreaking());
    }

    #[Test]
    public function it_reports_query_parameter_changes(): void
    {
        $old = $this->spec(['parameters' => [
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
            ['name' => 'legacy', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Old.'],
        ]]);

        $new = $this->spec(['parameters' => [
            ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'filter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'description' => 'New.'],
        ]]);

        $summaries = $this->summaries($old, $new);

        $this->assertContains('Added query parameter `filter`', $summaries);
        $this->assertContains('Removed query parameter `legacy`', $summaries);
        $this->assertContains('Query parameter `page` changed from integer to string', $summaries);
        $this->assertContains('Query parameter `page` is now required', $summaries);
        $this->assertContains('Query parameter `sort` allowed values changed to asc, desc', $summaries);
        $this->assertContains('Query parameter `sort` description updated', $summaries);
    }

    #[Test]
    public function making_a_parameter_required_is_breaking(): void
    {
        $changes = $this->differ->diff(
            $this->spec(['parameters' => [['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']]]]),
            $this->spec(['parameters' => [['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']]]]),
        );

        $this->assertTrue($changes[0]->isBreaking());
    }

    #[Test]
    public function it_reports_header_changes(): void
    {
        $summaries = $this->summaries(
            $this->spec(['x-headers' => [['name' => 'Accept', 'value' => 'application/json', 'required' => true]]]),
            $this->spec(['x-headers' => [
                ['name' => 'Accept', 'value' => 'application/json', 'required' => true],
                ['name' => 'X-Tenant', 'value' => 'acme', 'required' => true],
            ]]),
        );

        $this->assertContains('Added header `X-Tenant` (required)', $summaries);
    }

    #[Test]
    public function it_reports_body_field_changes_including_nested_ones(): void
    {
        $body = fn (array $properties, array $required = []) => [
            'requestBody' => ['content' => ['application/json' => ['schema' => array_filter([
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ])]]],
        ];

        $old = $this->spec($body([
            'name' => ['type' => 'string'],
            'nickname' => ['type' => 'string'],
            'address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        ], ['name']));

        $new = $this->spec($body([
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string'], 'zip' => ['type' => 'string']]],
        ], ['name', 'age']));

        $summaries = $this->summaries($old, $new);

        $this->assertContains('Added body field `age` (required)', $summaries);
        $this->assertContains('Removed body field `nickname`', $summaries);
        $this->assertContains('Added body field `address.zip`', $summaries);
    }

    #[Test]
    public function it_reports_when_a_body_appears_or_disappears(): void
    {
        $withBody = $this->spec(['requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]]);

        $this->assertContains('Request body added (application/json)', $this->summaries($this->spec([]), $withBody));
        $this->assertContains('Request body removed', $this->summaries($withBody, $this->spec([])));
    }

    #[Test]
    public function it_reports_a_changed_media_type(): void
    {
        $summaries = $this->summaries(
            $this->spec(['requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]]),
            $this->spec(['requestBody' => ['content' => ['multipart/form-data' => ['schema' => ['type' => 'object']]]]]),
        );

        $this->assertContains('Request body is now multipart/form-data', $summaries);
    }

    #[Test]
    public function it_reports_response_changes(): void
    {
        $old = $this->spec(['responses' => [
            '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'legacy' => ['type' => 'string']],
            ]]]],
            '410' => ['description' => 'Gone'],
        ]]);

        $new = $this->spec(['responses' => [
            '200' => ['description' => 'The user', 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'email' => ['type' => 'string']],
            ]]]],
            '422' => ['description' => 'Unprocessable'],
        ]]);

        $summaries = $this->summaries($old, $new);

        $this->assertContains('Response 422 added', $summaries);
        $this->assertContains('Response 410 removed', $summaries);
        $this->assertContains('Response 200 description updated', $summaries);
        $this->assertContains('Added 200 response field `email`', $summaries);
        $this->assertContains('Removed 200 response field `legacy`', $summaries);
        $this->assertContains('200 response field `id` changed from integer to string', $summaries);
    }

    #[Test]
    public function a_removed_response_field_is_breaking(): void
    {
        $changes = $this->differ->diff(
            $this->spec(['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['id' => ['type' => 'integer']],
            ]]]]]]),
            $this->spec(['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => [],
            ]]]]]]),
        );

        $this->assertTrue($changes[0]->isBreaking());
    }

    #[Test]
    public function a_new_optional_field_is_not_breaking(): void
    {
        $changes = $this->differ->diff(
            $this->spec(['parameters' => []]),
            $this->spec(['parameters' => [['name' => 'filter', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']]]]),
        );

        $this->assertCount(1, $changes);
        $this->assertFalse($changes[0]->isBreaking());
        $this->assertSame('Changed', $changes[0]->label());
    }

    #[Test]
    public function it_diffs_several_endpoints_at_once(): void
    {
        $old = Spec::fromArray(['paths' => [
            '/a' => ['get' => ['summary' => 'A', 'responses' => []]],
            '/b' => ['get' => ['summary' => 'B', 'responses' => []]],
        ]]);

        $new = Spec::fromArray(['paths' => [
            '/a' => ['get' => ['summary' => 'A renamed', 'responses' => []]],
            '/c' => ['get' => ['summary' => 'C', 'responses' => []]],
        ]]);

        $types = [];

        foreach ($this->differ->diff($old, $new) as $operation) {
            $types[$operation->key()] = $operation->type;
        }

        $this->assertSame([
            'GET /a' => Change::MODIFIED,
            'GET /b' => Change::REMOVED,
            'GET /c' => Change::ADDED,
        ], $types);
    }

    #[Test]
    public function an_operation_change_round_trips_through_an_array(): void
    {
        $changes = $this->differ->diff(
            $this->spec(['summary' => 'Old']),
            $this->spec(['summary' => 'New']),
        );

        $restored = OperationChange::fromArray($changes[0]->toArray());

        $this->assertEquals($changes[0], $restored);
        $this->assertSame('put-users-user', $restored->id());
    }
}
