<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Support\DocBlockParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DocBlockParserTest extends TestCase
{
    protected DocBlockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DocBlockParser();
    }

    #[Test]
    public function it_returns_an_empty_docblock_for_nothing(): void
    {
        $this->assertTrue($this->parser->parse(null)->isEmpty());
        $this->assertTrue($this->parser->parse(false)->isEmpty());
        $this->assertTrue($this->parser->parse('   ')->isEmpty());
    }

    #[Test]
    public function it_splits_the_summary_from_the_description(): void
    {
        $block = $this->parser->parse(<<<'DOC'
        /**
         * Create a user
         *
         * Creates the account and sends the welcome email.
         * The password is hashed before it is stored.
         *
         * @group Users
         */
        DOC);

        $this->assertSame('Create a user', $block->summary);
        $this->assertStringContainsString('welcome email', $block->description);
        $this->assertStringContainsString('hashed', $block->description);
        $this->assertSame('Users', $block->tag('group'));
    }

    #[Test]
    public function it_keeps_multi_line_tag_values_together(): void
    {
        $block = $this->parser->parse(<<<'DOC'
        /**
         * @response 201 {
         *   "data": {
         *     "id": 1
         *   }
         * }
         */
        DOC);

        $response = $this->parser->parseResponseTag($block->tag('response'));

        $this->assertSame(201, $response['status']);
        $this->assertSame(['data' => ['id' => 1]], $response['content']);
    }

    #[Test]
    public function it_collects_repeated_tags(): void
    {
        $block = $this->parser->parse(<<<'DOC'
        /**
         * @bodyParam name string required The name.
         * @bodyParam email string required The email.
         */
        DOC);

        $this->assertCount(2, $block->tags('bodyparam'));
        $this->assertTrue($block->hasTag('bodyParam'), 'Tag lookups are case insensitive.');
    }

    #[Test]
    public function it_parses_a_parameter_tag(): void
    {
        $parsed = $this->parser->parseParamTag('age integer required The age of the user. Example: 34');

        $this->assertSame([
            'name' => 'age',
            'type' => 'integer',
            'required' => true,
            'description' => 'The age of the user.',
            'example' => 34,
        ], $parsed);
    }

    #[Test]
    public function a_parameter_tag_only_needs_a_name(): void
    {
        $parsed = $this->parser->parseParamTag('token');

        $this->assertSame('token', $parsed['name']);
        $this->assertSame('string', $parsed['type']);
        $this->assertFalse($parsed['required']);
        $this->assertNull($parsed['example']);
    }

    #[Test]
    public function it_casts_examples_to_their_natural_type(): void
    {
        $this->assertSame(34, $this->parser->castExample('34'));
        $this->assertSame(1.5, $this->parser->castExample('1.5'));
        $this->assertTrue($this->parser->castExample('true'));
        $this->assertNull($this->parser->castExample('null'));
        $this->assertSame(['a' => 1], $this->parser->castExample('{"a": 1}'));
        $this->assertSame('plain', $this->parser->castExample('"plain"'));
    }

    #[Test]
    public function it_normalises_type_aliases(): void
    {
        $this->assertSame('integer', $this->parser->normaliseType('int'));
        $this->assertSame('number', $this->parser->normaliseType('float'));
        $this->assertSame('boolean', $this->parser->normaliseType('BOOL'));
        $this->assertSame('string[]', $this->parser->normaliseType('string[]'));
    }

    #[Test]
    public function a_response_tag_defaults_to_200_and_keeps_its_description(): void
    {
        $response = $this->parser->parseResponseTag('The current user. {"id": 1}');

        $this->assertSame(200, $response['status']);
        $this->assertSame('The current user.', $response['description']);
        $this->assertSame(['id' => 1], $response['content']);
    }

    #[Test]
    public function a_response_tag_may_carry_no_body_at_all(): void
    {
        $response = $this->parser->parseResponseTag('404 The user does not exist.');

        $this->assertSame(404, $response['status']);
        $this->assertSame('The user does not exist.', $response['description']);
        $this->assertNull($response['content']);
    }
}
