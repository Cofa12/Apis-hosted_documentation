<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Everything a project can declare by hand, without a single attribute.
 */
class DocBlockDocumentationTest extends TestCase
{
    #[Test]
    public function the_group_comes_from_the_class_docblock(): void
    {
        $this->assertSame('Reports', $this->endpoint('POST', 'api/reports/{report}')->group);
        $this->assertSame(
            'Scheduled and ad-hoc reporting.',
            $this->spec()->tagDescription('Reports')
        );
    }

    #[Test]
    public function the_summary_and_description_come_from_the_method_docblock(): void
    {
        $endpoint = $this->endpoint('POST', 'api/reports/{report}');

        $this->assertSame('Build a report', $endpoint->title);
        $this->assertSame('Queues a report and returns the recipients it will be sent to.', $endpoint->description);
    }

    #[Test]
    public function the_authenticated_tag_marks_the_endpoint(): void
    {
        $endpoint = $this->endpoint('POST', 'api/reports/{report}');

        $this->assertTrue($endpoint->authenticated, 'Even without auth middleware.');
        $this->assertContains('Authorization', array_map(fn ($h) => $h->name, $endpoint->headers));
    }

    #[Test]
    public function a_header_tag_carries_a_value_and_a_description(): void
    {
        $headers = $this->endpoint('POST', 'api/reports/{report}')->headers;
        $tenant = null;

        foreach ($headers as $header) {
            if ($header->name === 'X-Tenant') {
                $tenant = $header;
            }
        }

        $this->assertNotNull($tenant);
        $this->assertSame('acme', $tenant->value);
        $this->assertSame('The tenant the report belongs to.', $tenant->description);
    }

    #[Test]
    public function url_query_and_body_tags_land_in_the_right_bucket(): void
    {
        $endpoint = $this->endpoint('POST', 'api/reports/{report}');

        $report = $this->parameter($endpoint->urlParameters, 'report');
        $this->assertSame('string', $report->type, 'The tag overrides the inferred type.');
        $this->assertSame('monthly-revenue', $report->example);
        $this->assertTrue($report->required);

        $preview = $this->parameter($endpoint->queryParameters, 'preview');
        $this->assertSame('boolean', $preview->type);
        $this->assertTrue($preview->example);

        $recipients = $this->parameter($endpoint->bodyParameters, 'recipients');
        $this->assertSame('string[]', $recipients->type);
        $this->assertTrue($recipients->required);
        $this->assertSame(['ops@example.com'], $recipients->example);

        $this->assertSame('object', $this->parameter($endpoint->bodyParameters, 'options')->type);
    }

    #[Test]
    public function an_api_resource_tag_builds_the_response_body(): void
    {
        $responses = $this->endpoint('POST', 'api/reports/{report}')->responses;
        $accepted = null;

        foreach ($responses as $response) {
            if ($response->status === 202) {
                $accepted = $response;
            }
        }

        $this->assertNotNull($accepted, 'The @apiResource tag produced a 202.');
        $this->assertSame('UserResource', $accepted->schemaName);
        $this->assertSame('John Doe', $accepted->content['data']['name']);
    }

    #[Test]
    public function a_response_tag_without_a_body_still_documents_the_status(): void
    {
        $statuses = [];

        foreach ($this->endpoint('POST', 'api/reports/{report}')->responses as $response) {
            $statuses[$response->status] = $response->description;
        }

        $this->assertSame('Another run is already in progress.', $statuses[409]);
    }

    #[Test]
    public function the_declared_types_survive_into_the_openapi_document(): void
    {
        $operation = $this->spec()->toArray()['paths']['/api/reports/{report}']['post'];
        $schema = $operation['requestBody']['content']['application/json']['schema'];

        $this->assertSame('array', $schema['properties']['recipients']['type']);
        $this->assertSame('string', $schema['properties']['recipients']['items']['type']);
        $this->assertSame(['recipients'], $schema['required']);
        $this->assertSame('object', $schema['properties']['options']['type']);
        $this->assertArrayHasKey('202', $operation['responses']);
        $this->assertArrayHasKey('409', $operation['responses']);
    }

    /** @param array<int, Parameter> $parameters */
    protected function parameter(array $parameters, string $name): Parameter
    {
        foreach ($parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        $this->fail("No parameter named [{$name}].");
    }
}
