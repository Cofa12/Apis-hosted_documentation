<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\DocumentationGenerator;
use Cofa\ApiDocs\Exceptions\DocumentationConflictException;
use Cofa\ApiDocs\Scanning\Conflict;
use Cofa\ApiDocs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule: where a docblock tag and a PHP attribute describe the same thing,
 * the attribute wins — field by field, and never silently.
 */
class PrecedenceTest extends TestCase
{
    protected string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir() . '/api-docs-precedence-' . bin2hex(random_bytes(4));

        $this->withConfig([
            'api-docs.output.views_path' => $this->output . '/views',
            'api-docs.output.spec_file' => $this->output . '/views/openapi.json',
            'api-docs.history.path' => $this->output . '/history.json',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->output !== '' && File::isDirectory($this->output)) {
            File::deleteDirectory($this->output);
        }

        parent::tearDown();
    }

    /** @return array<int, Conflict> */
    protected function conflicts(): array
    {
        $generator = $this->app->make(DocumentationGenerator::class);
        $generator->endpoints();

        return $generator->conflicts();
    }

    /** @return array<string, Conflict> keyed by "location field property" */
    protected function conflictMap(): array
    {
        $map = [];

        foreach ($this->conflicts() as $conflict) {
            $key = $conflict->field === null
                ? $conflict->property
                : $conflict->location . ' ' . $conflict->field . ' ' . $conflict->property;

            $map[$key] = $conflict;
        }

        return $map;
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

    #[Test]
    public function the_attribute_wins_on_the_fields_it_declares(): void
    {
        $endpoint = $this->endpoint('PUT', 'api/conflicts');

        $this->assertSame('Attribute summary', $endpoint->title);
        $this->assertSame('Attribute Group', $endpoint->group);
        $this->assertFalse($endpoint->authenticated, '#[Unauthenticated] overrules @authenticated.');
        $this->assertFalse($this->parameter($endpoint->bodyParameters, 'email')->required);
        $this->assertSame('string', $this->parameter($endpoint->queryParameters, 'notify')->type);
    }

    #[Test]
    public function the_docblock_survives_on_the_fields_the_attribute_leaves_alone(): void
    {
        $endpoint = $this->endpoint('PUT', 'api/conflicts');
        $email = $this->parameter($endpoint->bodyParameters, 'email');

        // The attribute only said `required`, so the rest is still the docblock's.
        $this->assertSame('The email, from the docblock.', $email->description);
        $this->assertSame('string', $email->type);
    }

    #[Test]
    public function naming_a_parameter_without_describing_it_changes_nothing(): void
    {
        // #[ApiParam(name: 'nickname')] asserts the field exists, nothing more.
        $nickname = $this->parameter($this->endpoint('PUT', 'api/conflicts')->bodyParameters, 'nickname');

        $this->assertSame('The nickname, from the docblock.', $nickname->description);
        $this->assertSame('doc-nickname', $nickname->example);
        $this->assertFalse($nickname->required);
    }

    #[Test]
    public function both_sources_still_contribute_the_fields_only_they_define(): void
    {
        $endpoint = $this->endpoint('PUT', 'api/conflicts');
        $names = array_map(fn (Parameter $p) => $p->name, $endpoint->bodyParameters);

        // Precedence is per field, not "one source replaces the other".
        $this->assertContains('email', $names);
        $this->assertContains('nickname', $names);
        $this->assertSame(['notify'], array_map(fn (Parameter $p) => $p->name, $endpoint->queryParameters));
    }

    #[Test]
    public function every_disagreement_is_recorded(): void
    {
        $map = $this->conflictMap();

        $this->assertArrayHasKey('summary', $map);
        $this->assertArrayHasKey('group', $map);
        $this->assertArrayHasKey('authenticated', $map);
        $this->assertArrayHasKey('body param email required', $map);
        $this->assertArrayHasKey('query param notify type', $map);
        $this->assertArrayHasKey('header X-Tenant value', $map);
        $this->assertArrayHasKey('response 200 content', $map);
    }

    #[Test]
    public function a_conflict_reads_the_way_it_was_specified(): void
    {
        $conflict = $this->conflictMap()['body param email required'];

        $this->assertSame(
            'ConflictController::update — body param `email`: docblock and #[ApiParam] disagree '
                . '(required: true vs false). Using attribute value.',
            $conflict->message()
        );
    }

    #[Test]
    public function an_operation_level_conflict_names_the_attribute_that_won(): void
    {
        $map = $this->conflictMap();

        $this->assertSame(
            'ConflictController::update — summary: docblock and #[ApiDoc] disagree '
                . '("Docblock summary" vs "Attribute summary"). Using attribute value.',
            $map['summary']->message()
        );

        $this->assertStringContainsString('#[ApiGroup]', $map['group']->message());
        $this->assertStringContainsString('#[Authenticated] disagree (true vs false)', $map['authenticated']->message());
        $this->assertStringContainsString('#[ApiHeader]', $map['header X-Tenant value']->message());
        $this->assertStringContainsString('#[ApiResponse]', $map['response 200 content']->message());
    }

    #[Test]
    public function agreeing_sources_are_not_a_conflict(): void
    {
        foreach ($this->conflicts() as $conflict) {
            $this->assertStringNotContainsString('::agree', $conflict->handler);
        }
    }

    #[Test]
    public function a_parameter_that_only_one_source_describes_is_not_a_conflict(): void
    {
        $map = $this->conflictMap();

        $this->assertArrayNotHasKey('body param nickname type', $map);
        $this->assertArrayNotHasKey('body param nickname required', $map);
        $this->assertArrayNotHasKey('body param nickname example', $map);
    }

    #[Test]
    public function inferred_documentation_is_never_reported_as_a_conflict(): void
    {
        // The user endpoints infer plenty and also carry docblocks; only the
        // two sources of *hand written* documentation can disagree.
        foreach ($this->conflicts() as $conflict) {
            $this->assertStringContainsString('ConflictController', $conflict->handler);
        }
    }

    #[Test]
    public function generate_warns_about_every_disagreement(): void
    {
        Artisan::call('api-docs:generate --no-views');
        $output = Artisan::output();

        $this->assertStringContainsString('documentation conflicts between docblocks and attributes', $output);
        $this->assertStringContainsString(
            '- ConflictController::update — body param `email`: docblock and #[ApiParam] disagree '
                . '(required: true vs false). Using attribute value.',
            $output
        );
        $this->assertFileExists($this->output . '/views/openapi.json', 'A warning does not stop the run.');
    }

    #[Test]
    public function strict_mode_fails_the_generate_command_instead(): void
    {
        $this->withConfig([
            'api-docs.strict_precedence' => true,
            'api-docs.output.spec_file' => $this->output . '/views/openapi.json',
        ]);

        $this->expectException(DocumentationConflictException::class);
        $this->expectExceptionMessage('body param `email`');

        Artisan::call('api-docs:generate --no-views');
    }

    #[Test]
    public function strict_mode_writes_nothing_at_all(): void
    {
        $this->withConfig([
            'api-docs.strict_precedence' => true,
            'api-docs.output.spec_file' => $this->output . '/views/openapi.json',
            'api-docs.history.path' => $this->output . '/history.json',
        ]);

        try {
            Artisan::call('api-docs:generate --no-views');
            $this->fail('The command should have failed.');
        } catch (DocumentationConflictException) {
            // A rejected document must not be left behind for a later step.
        }

        $this->assertFileDoesNotExist($this->output . '/views/openapi.json');
        $this->assertFileDoesNotExist($this->output . '/history.json');
    }

    #[Test]
    public function the_strict_exception_carries_every_conflict(): void
    {
        $exception = new DocumentationConflictException($this->conflicts());

        $this->assertGreaterThanOrEqual(7, count($exception->conflicts()));
        $this->assertStringContainsString('strict_precedence is on', $exception->getMessage());
        $this->assertStringContainsString('docblock and #[ApiParam] disagree', $exception->getMessage());
    }

    #[Test]
    public function strict_mode_does_not_break_the_documentation_page(): void
    {
        $this->withConfig(['api-docs.strict_precedence' => true]);

        // Drift should fail a build, never take the docs down.
        $this->get('/api/documentation')->assertOk();
        $this->getJson('/api/documentation.json')->assertOk();
    }
}
