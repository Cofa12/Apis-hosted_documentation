<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Support\ExampleFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExampleFactoryTest extends TestCase
{
    #[Test]
    #[DataProvider('nameProvider')]
    public function it_produces_examples_that_match_the_field_name(string $name, mixed $expected): void
    {
        $this->assertSame($expected, ExampleFactory::forName($name));
    }

    public static function nameProvider(): array
    {
        return [
            ['email', 'john@example.com'],
            ['first_name', 'John'],
            ['author_id', 1],
            ['id', 1],
            ['created_at', '2026-01-15T09:30:00.000000Z'],
            ['published_date', '2026-01-15T09:30:00.000000Z'],
            ['is_active', true],
            ['has_children', true],
            ['comments_count', 3],
            ['profile_url', 'https://example.com/resource'],
            ['contact_email', 'john@example.com'],
            ['per_page', 15],
        ];
    }

    #[Test]
    public function it_falls_back_to_the_declared_type(): void
    {
        $this->assertSame(1, ExampleFactory::forType('integer'));
        $this->assertSame(1.5, ExampleFactory::forType('number'));
        $this->assertTrue(ExampleFactory::forType('boolean'));
        $this->assertSame(['first', 'second'], ExampleFactory::forType('array'));
        $this->assertSame('(binary)', ExampleFactory::forType('file'));
        $this->assertSame([], ExampleFactory::forType('object'), 'Examples stay array shaped so they survive JSON.');
    }

    #[Test]
    public function the_first_allowed_value_wins_over_everything_else(): void
    {
        $this->assertSame('draft', ExampleFactory::forParameter('status', 'string', ['in:draft,live'], ['draft', 'live']));
    }

    #[Test]
    public function it_reads_examples_out_of_the_validation_rules(): void
    {
        $this->assertSame('2026-01-15', ExampleFactory::forParameter('starts', 'date', ['date']));
        $this->assertSame('192.168.1.1', ExampleFactory::forParameter('server', 'string', ['ip']));
        $this->assertSame('https://example.com', ExampleFactory::forParameter('site', 'string', ['url']));
        $this->assertSame(18, ExampleFactory::forParameter('age', 'integer', ['integer', 'min:18']));
    }

    #[Test]
    public function it_honours_a_minimum_length(): void
    {
        $example = ExampleFactory::forParameter('nickname', 'string', ['string', 'min:12']);

        $this->assertGreaterThanOrEqual(12, mb_strlen((string) $example));
    }

    #[Test]
    public function it_casts_enum_values_to_the_declared_type(): void
    {
        $this->assertSame(1, ExampleFactory::forParameter('level', 'integer', [], ['1', '2']));
    }

    #[Test]
    public function nested_names_use_only_the_last_segment(): void
    {
        $this->assertSame('London', ExampleFactory::forName('address.city'));
        $this->assertSame('John Doe', ExampleFactory::forName('contacts.*.name'));
    }
}
