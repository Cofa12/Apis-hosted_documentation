<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Data\Endpoint;
use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Scanning\Conflict;
use Cofa\ApiDocs\Scanning\PrecedenceGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PrecedenceGuardTest extends TestCase
{
    protected PrecedenceGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PrecedenceGuard();
    }

    protected function endpoint(): Endpoint
    {
        $endpoint = new Endpoint(['PUT'], 'api/users/{user}');
        $endpoint->controller = 'App\\Http\\Controllers\\UserController';
        $endpoint->action = 'update';

        return $endpoint;
    }

    /** @return array<int, string> */
    protected function messages(array $docblock, array $attribute): array
    {
        return array_map(
            fn (Conflict $conflict) => $conflict->message(),
            $this->guard->compare($this->endpoint(), $docblock, $attribute)
        );
    }

    #[Test]
    public function nothing_documented_twice_means_nothing_to_report(): void
    {
        $this->assertSame([], $this->messages([], []));
    }

    #[Test]
    public function only_overlapping_fields_are_compared(): void
    {
        // The docblock describes email, the attribute describes notify:
        // both apply, neither is overruled.
        $messages = $this->messages(
            ['bodyParameters' => ['email' => ['required' => true]]],
            ['bodyParameters' => ['notify' => ['required' => false]]],
        );

        $this->assertSame([], $messages);
    }

    #[Test]
    public function matching_values_are_not_a_conflict(): void
    {
        $this->assertSame([], $this->messages(
            ['bodyParameters' => ['email' => ['type' => 'string', 'required' => true]]],
            ['bodyParameters' => ['email' => ['type' => 'string', 'required' => true]]],
        ));
    }

    #[Test]
    public function surrounding_whitespace_is_not_a_disagreement(): void
    {
        $this->assertSame([], $this->messages(
            ['operation' => ['summary' => 'Update user']],
            ['operation' => ['summary' => '  Update user  ']],
        ));
    }

    #[Test]
    public function it_reports_a_disagreeing_parameter_field(): void
    {
        $this->assertSame(
            ['UserController::update — body param `email`: docblock and #[ApiParam] disagree '
                . '(required: true vs false). Using attribute value.'],
            $this->messages(
                ['bodyParameters' => ['email' => ['required' => true]]],
                ['bodyParameters' => ['email' => ['required' => false]]],
            )
        );
    }

    #[Test]
    public function each_bucket_reads_the_way_a_developer_would_describe_it(): void
    {
        $messages = $this->messages(
            [
                'queryParameters' => ['page' => ['type' => 'integer']],
                'urlParameters' => ['user' => ['type' => 'integer']],
                'headers' => ['X-Key' => ['value' => 'a']],
                'responses' => ['404' => ['description' => 'Gone']],
            ],
            [
                'queryParameters' => ['page' => ['type' => 'string']],
                'urlParameters' => ['user' => ['type' => 'string']],
                'headers' => ['X-Key' => ['value' => 'b']],
                'responses' => ['404' => ['description' => 'Missing']],
            ],
        );

        $this->assertStringContainsString('query param `page`', $messages[0]);
        $this->assertStringContainsString('url param `user`', $messages[1]);
        $this->assertStringContainsString('header `X-Key`: docblock and #[ApiHeader]', $messages[2]);
        $this->assertStringContainsString('response `404`: docblock and #[ApiResponse]', $messages[3]);
    }

    #[Test]
    public function operation_level_conflicts_leave_out_the_repeated_property(): void
    {
        $messages = $this->messages(
            ['operation' => ['summary' => 'Old', 'authenticated' => true, 'deprecated' => false]],
            ['operation' => ['summary' => 'New', 'authenticated' => false, 'deprecated' => true]],
        );

        $joined = implode("\n", $messages);

        $this->assertStringContainsString(
            'UserController::update — summary: docblock and #[ApiDoc] disagree ("Old" vs "New"). Using attribute value.',
            $joined
        );
        $this->assertStringContainsString('authenticated: docblock and #[Authenticated] disagree (true vs false)', $joined);
        $this->assertStringContainsString('deprecated: docblock and #[ApiDoc] disagree (false vs true)', $joined);
    }

    #[Test]
    public function a_closure_route_is_named_by_its_path(): void
    {
        $endpoint = new Endpoint(['GET'], 'api/ping');

        $conflicts = $this->guard->compare(
            $endpoint,
            ['operation' => ['summary' => 'A']],
            ['operation' => ['summary' => 'B']],
        );

        $this->assertStringStartsWith('GET /api/ping — summary', $conflicts[0]->message());
    }

    #[Test]
    public function values_are_rendered_readably(): void
    {
        $render = fn (mixed $doc, mixed $attribute) => (new Conflict('H', 'body param', 'f', 'p', $doc, $attribute))->message();

        $this->assertStringContainsString('(p: null vs 3)', $render(null, 3));
        $this->assertStringContainsString('(p: 1.5 vs "x")', $render(1.5, 'x'));
        $this->assertStringContainsString('(p: ["a","b"] vs [])', $render(['a', 'b'], []));
    }

    #[Test]
    public function a_conflict_round_trips_through_an_array(): void
    {
        $conflict = new Conflict('UserController::update', 'body param', 'email', 'required', true, false);
        $restored = Conflict::fromArray($conflict->toArray());

        $this->assertEquals($conflict, $restored);
        $this->assertSame($conflict->message(), $restored->message());
        $this->assertArrayHasKey('message', $conflict->toArray());
    }

    #[Test]
    public function an_explicit_definition_only_overrides_what_it_declares(): void
    {
        $inferred = new Parameter('email', 'string', true, 'From the rules.', 'a@b.test');
        $explicit = new Parameter('email', 'integer', false, '', null, declared: ['required']);

        $inferred->mergeFrom($explicit, preferOther: true);

        $this->assertFalse($inferred->required, 'The declared field is applied.');
        $this->assertSame('string', $inferred->type, 'An undeclared field is left alone.');
        $this->assertSame('From the rules.', $inferred->description);
        $this->assertSame('a@b.test', $inferred->example);
    }

    #[Test]
    public function a_definition_that_declares_nothing_changes_nothing(): void
    {
        $inferred = new Parameter('email', 'string', true, 'From the rules.');
        $inferred->mergeFrom(new Parameter('email', 'integer', false, 'Other.', declared: []), preferOther: true);

        $this->assertSame('string', $inferred->type);
        $this->assertTrue($inferred->required);
        $this->assertSame('From the rules.', $inferred->description);
    }

    #[Test]
    public function an_inferred_definition_still_speaks_for_every_field(): void
    {
        // declared === null means "no declaration list", the old behaviour.
        $first = new Parameter('email', 'string', false, 'Inferred.');
        $first->mergeFrom(new Parameter('email', 'integer', true, 'Documented.'), preferOther: true);

        $this->assertSame('integer', $first->type);
        $this->assertTrue($first->required);
        $this->assertSame('Documented.', $first->description);
    }
}
