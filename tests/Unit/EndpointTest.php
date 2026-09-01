<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\HeaderParam;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Data\ResponseExample;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EndpointTest extends TestCase
{
    #[Test]
    public function it_picks_the_most_specific_verb_for_display(): void
    {
        $this->assertSame('POST', (new Endpoint(['GET', 'POST'], 'api/users'))->method());
        $this->assertSame('GET', (new Endpoint(['GET'], 'api/users'))->method());
        $this->assertSame('TRACE', (new Endpoint(['TRACE'], 'api/users'))->method());
    }

    #[Test]
    public function it_builds_a_stable_anchor_id(): void
    {
        $this->assertSame('get-api-users-user', (new Endpoint(['GET'], 'api/users/{user}'))->id());
        $this->assertSame('get', (new Endpoint(['GET'], ''))->id(), 'The root path still gets a usable anchor.');
    }

    #[Test]
    public function it_knows_whether_it_carries_a_body(): void
    {
        $this->assertTrue((new Endpoint(['POST'], 'a'))->hasBody());
        $this->assertFalse((new Endpoint(['GET'], 'a'))->hasBody());
    }

    #[Test]
    public function it_falls_back_to_the_route_for_a_title(): void
    {
        $endpoint = new Endpoint(['DELETE'], 'api/users/{user}');

        $this->assertSame('DELETE /api/users/{user}', $endpoint->displayTitle());

        $endpoint->title = 'Delete user';
        $this->assertSame('Delete user', $endpoint->displayTitle());
    }

    #[Test]
    public function responses_are_deduplicated_by_status(): void
    {
        $endpoint = new Endpoint(['GET'], 'a');
        $endpoint->addResponse(new ResponseExample(200, ['first' => true]));
        $endpoint->addResponse(new ResponseExample(200, ['second' => true]), overwrite: false);

        $this->assertCount(1, $endpoint->responses);
        $this->assertSame(['first' => true], $endpoint->responses[0]->content);

        $endpoint->addResponse(new ResponseExample(200, ['third' => true]));
        $this->assertSame(['third' => true], $endpoint->responses[0]->content);
    }

    #[Test]
    public function headers_are_replaced_case_insensitively(): void
    {
        $endpoint = new Endpoint(['GET'], 'a');
        $endpoint->addHeader(new HeaderParam('Accept', 'application/json'));
        $endpoint->addHeader(new HeaderParam('accept', 'text/plain'));

        $this->assertCount(1, $endpoint->headers);
        $this->assertSame('text/plain', $endpoint->headers[0]->value);
    }

    #[Test]
    public function merging_parameters_keeps_what_is_already_known(): void
    {
        $endpoint = new Endpoint(['POST'], 'a');
        $endpoint->bodyParameters = [new Parameter('name', 'string', true, 'From the rules.')];

        $endpoint->mergeParameters('bodyParameters', [new Parameter('name', 'string', false, '', 'Ada')]);

        $this->assertCount(1, $endpoint->bodyParameters);
        $this->assertSame('From the rules.', $endpoint->bodyParameters[0]->description);
        $this->assertSame('Ada', $endpoint->bodyParameters[0]->example);
        $this->assertTrue($endpoint->bodyParameters[0]->required);
    }

    #[Test]
    public function an_explicit_definition_can_take_over(): void
    {
        $endpoint = new Endpoint(['POST'], 'a');
        $endpoint->bodyParameters = [new Parameter('name', 'string', true, 'Inferred.')];

        $endpoint->mergeParameters('bodyParameters', [new Parameter('name', 'string', false, 'Documented.')], preferNew: true);

        $this->assertSame('Documented.', $endpoint->bodyParameters[0]->description);
        $this->assertFalse($endpoint->bodyParameters[0]->required);
    }

    #[Test]
    public function responses_split_into_successes_and_failures(): void
    {
        $endpoint = new Endpoint(['GET'], 'a');
        $endpoint->addResponse(new ResponseExample(500));
        $endpoint->addResponse(new ResponseExample(201));
        $endpoint->sortResponses();

        $this->assertSame([201, 500], array_map(fn ($r) => $r->status, $endpoint->responses));
        $this->assertCount(1, $endpoint->successResponses());
        $this->assertCount(1, $endpoint->errorResponses());
    }

    #[Test]
    public function it_round_trips_through_an_array(): void
    {
        $endpoint = new Endpoint(['PUT'], 'api/users/{user}');
        $endpoint->title = 'Update user';
        $endpoint->group = 'Users';
        $endpoint->authenticated = true;
        $endpoint->urlParameters = [new Parameter('user', 'integer', true)];
        $endpoint->bodyParameters = [new Parameter('name', 'string', true, 'The name.', 'Ada', [], ['required'])];
        $endpoint->headers = [new HeaderParam('Accept', 'application/json', true)];
        $endpoint->addResponse(new ResponseExample(200, ['ok' => true], 'Updated', schemaName: 'UserResource'));

        $restored = Endpoint::fromArray($endpoint->toArray());

        $this->assertEquals($endpoint, $restored);
        $this->assertSame('UserResource', $restored->responses[0]->schemaName);
    }

    #[Test]
    public function a_response_body_is_pretty_printed(): void
    {
        $this->assertSame("{\n    \"a\": 1\n}", (new ResponseExample(200, ['a' => 1]))->body());
        $this->assertSame('raw text', (new ResponseExample(200, 'raw text'))->body());
        $this->assertSame('', (new ResponseExample(204))->body());
        $this->assertSame('No Content', (new ResponseExample(204))->statusText());
    }
}
