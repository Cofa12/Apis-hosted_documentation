<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Support\ValidationRuleParser;
use Cofa\ApiDocs\Tests\Fixtures\Enums\UserStatus;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ValidationRuleParserTest extends TestCase
{
    protected ValidationRuleParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ValidationRuleParser();
    }

    #[Test]
    public function it_splits_pipe_separated_rules(): void
    {
        $this->assertSame(['required', 'string', 'max:255'], $this->parser->normalise('required|string|max:255'));
    }

    #[Test]
    public function it_flattens_nested_rule_arrays(): void
    {
        $this->assertSame(['required', 'email', 'max:60'], $this->parser->normalise([['required', 'email'], 'max:60']));
    }

    #[Test]
    public function it_stringifies_fluent_rule_objects(): void
    {
        $rules = $this->parser->normalise(['required', Rule::in(['a', 'b'])]);

        $this->assertSame('required', $rules[0]);
        $this->assertStringStartsWith('in:', $rules[1]);
    }

    #[Test]
    public function it_expands_an_enum_rule_into_its_cases(): void
    {
        $rules = $this->parser->normalise([Rule::enum(UserStatus::class)]);

        $this->assertSame(['in:active,suspended,banned'], $rules);
    }

    #[Test]
    #[DataProvider('typeProvider')]
    public function it_maps_rules_to_types(string $rules, string $expected): void
    {
        $this->assertSame($expected, $this->parser->resolveType('field', $this->parser->normalise($rules)));
    }

    public static function typeProvider(): array
    {
        return [
            'string' => ['required|string', 'string'],
            'integer' => ['required|integer', 'integer'],
            'numeric' => ['numeric', 'number'],
            'boolean' => ['boolean', 'boolean'],
            'array' => ['array|min:1', 'array'],
            'file' => ['file|mimes:pdf', 'file'],
            'image' => ['image', 'file'],
            'date' => ['date', 'date'],
            'email is a string' => ['email', 'string'],
            'untyped defaults to string' => ['required', 'string'],
        ];
    }

    #[Test]
    public function it_infers_a_type_from_the_field_name_when_no_rule_says_so(): void
    {
        $this->assertSame('integer', $this->parser->resolveType('author_id', ['required']));
        $this->assertSame('boolean', $this->parser->resolveType('is_active', ['required']));
    }

    #[Test]
    public function it_detects_required_fields(): void
    {
        $this->assertTrue($this->parser->isRequired(['required', 'string']));
        $this->assertTrue($this->parser->isRequired(['present']));
        $this->assertFalse($this->parser->isRequired(['nullable', 'sometimes', 'required_if:other,1']));
    }

    #[Test]
    public function it_extracts_enum_values(): void
    {
        $this->assertSame(['draft', 'published'], $this->parser->resolveEnum(['in:draft,published']));
        $this->assertSame([], $this->parser->resolveEnum(['string']));
    }

    #[Test]
    public function it_writes_constraints_as_readable_sentences(): void
    {
        $description = $this->parser->describe('password', $this->parser->normalise('required|string|min:8|confirmed'), 'string');

        $this->assertStringContainsString('Must be at least 8 characters.', $description);
        $this->assertStringContainsString('Must be confirmed with `password_confirmation`.', $description);
    }

    #[Test]
    public function it_words_boundaries_per_type(): void
    {
        $this->assertStringContainsString('Must have at most 5 items.', $this->parser->describe('tags', ['max:5'], 'array'));
        $this->assertStringContainsString('Maximum: 5.', $this->parser->describe('age', ['max:5'], 'integer'));
        $this->assertStringContainsString('Must be at most 5 kilobytes.', $this->parser->describe('file', ['max:5'], 'file'));
    }

    #[Test]
    public function it_describes_relational_and_conditional_rules(): void
    {
        $this->assertStringContainsString('Must exist in `users`.', $this->parser->describe('id', ['exists:users,id'], 'integer'));
        $this->assertStringContainsString('Must be unique in `users`.', $this->parser->describe('email', ['unique:users'], 'string'));
        $this->assertStringContainsString(
            'Required when `type` is `company`.',
            $this->parser->describe('vat', ['required_if:type,company'], 'string')
        );
        $this->assertStringContainsString(
            'Must be one of: `a`, `b` or `c`.',
            $this->parser->describe('kind', ['in:a,b,c'], 'string')
        );
    }

    #[Test]
    public function it_adds_a_confirmation_field_for_confirmed_rules(): void
    {
        $names = array_map(fn ($p) => $p->name, $this->parser->parse(['password' => 'required|confirmed|min:8']));

        $this->assertSame(['password', 'password_confirmation'], $names);
    }

    #[Test]
    public function it_builds_a_complete_parameter(): void
    {
        $parameters = $this->parser->parse(['email' => 'required|email|max:120']);
        $email = $parameters[0];

        $this->assertSame('email', $email->name);
        $this->assertSame('string', $email->type);
        $this->assertTrue($email->required);
        $this->assertSame('john@example.com', $email->example);
        $this->assertStringContainsString('valid email address', $email->description);
        $this->assertContains('max:120', $email->rules);
    }

    #[Test]
    public function it_ignores_non_string_field_names(): void
    {
        $this->assertSame([], $this->parser->parse([0 => 'required']));
    }
}
