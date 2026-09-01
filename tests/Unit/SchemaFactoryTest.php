<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\OpenApi\SchemaFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaFactoryTest extends TestCase
{
    protected SchemaFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SchemaFactory();
    }

    #[Test]
    public function it_maps_scalar_types(): void
    {
        $this->assertSame('string', $this->factory->fromParameter(new Parameter('a', 'string'))['type']);
        $this->assertSame('integer', $this->factory->fromParameter(new Parameter('a', 'integer'))['type']);
        $this->assertSame('number', $this->factory->fromParameter(new Parameter('a', 'number'))['type']);
        $this->assertSame('boolean', $this->factory->fromParameter(new Parameter('a', 'boolean'))['type']);
    }

    #[Test]
    public function a_file_becomes_a_binary_string(): void
    {
        $schema = $this->factory->fromParameter(new Parameter('avatar', 'file'));

        $this->assertSame('string', $schema['type']);
        $this->assertSame('binary', $schema['format']);
    }

    #[Test]
    public function it_derives_the_format_from_the_rules(): void
    {
        $this->assertSame('email', $this->factory->fromParameter(new Parameter('a', 'string', rules: ['email']))['format']);
        $this->assertSame('uuid', $this->factory->fromParameter(new Parameter('a', 'string', rules: ['uuid']))['format']);
        $this->assertSame('uri', $this->factory->fromParameter(new Parameter('a', 'string', rules: ['url']))['format']);
    }

    #[Test]
    public function it_translates_rules_into_json_schema_keywords(): void
    {
        $string = $this->factory->fromParameter(new Parameter('a', 'string', rules: ['min:2', 'max:60']));
        $this->assertSame(2, $string['minLength']);
        $this->assertSame(60, $string['maxLength']);

        $number = $this->factory->fromParameter(new Parameter('a', 'integer', rules: ['between:18,120']));
        $this->assertSame(18, $number['minimum']);
        $this->assertSame(120, $number['maximum']);

        $list = $this->factory->fromParameter(new Parameter('a', 'string[]', rules: ['min:1', 'max:5']));
        $this->assertSame(1, $list['minItems']);
        $this->assertSame(5, $list['maxItems']);

        $pattern = $this->factory->fromParameter(new Parameter('a', 'string', rules: ['regex:/^[a-z]+$/']));
        $this->assertSame('^[a-z]+$', $pattern['pattern']);

        $size = $this->factory->fromParameter(new Parameter('a', 'string', rules: ['size:5']));
        $this->assertSame(5, $size['minLength']);
        $this->assertSame(5, $size['maxLength']);
    }

    #[Test]
    public function a_nullable_parameter_uses_a_union_type(): void
    {
        $schema = $this->factory->fromParameter(new Parameter('a', 'integer', nullable: true));

        $this->assertSame(['integer', 'null'], $schema['type']);
    }

    #[Test]
    public function it_falls_back_to_the_openapi_30_nullable_flag(): void
    {
        $schema = (new SchemaFactory(nullableAsType: false))->fromParameter(new Parameter('a', 'string', nullable: true));

        $this->assertSame('string', $schema['type']);
        $this->assertTrue($schema['nullable']);
    }

    #[Test]
    public function it_builds_object_schemas_with_a_required_list(): void
    {
        $schema = $this->factory->objectFromParameters([
            new Parameter('name', 'string', true),
            new Parameter('nickname', 'string'),
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['name'], $schema['required']);
        $this->assertArrayHasKey('nickname', $schema['properties']);
    }

    #[Test]
    public function nested_parameters_become_nested_schemas(): void
    {
        $address = new Parameter('address', 'object', true, children: [
            new Parameter('city', 'string', true),
        ]);

        $schema = $this->factory->objectFromParameters([$address])['properties']['address'];

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['city'], $schema['required']);
    }

    #[Test]
    public function a_list_of_objects_becomes_an_array_of_object_schemas(): void
    {
        $rows = new Parameter('rows', 'object[]', children: [new Parameter('sku', 'string', true)]);
        $schema = $this->factory->fromParameter($rows);

        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        $this->assertSame(['sku'], $schema['items']['required']);
    }

    #[Test]
    public function it_infers_a_schema_from_an_example_payload(): void
    {
        $schema = $this->factory->fromExample([
            'id' => 1,
            'name' => 'Ada',
            'score' => 1.5,
            'active' => true,
            'email' => 'ada@example.com',
            'created_at' => '2026-01-15T09:30:00.000000Z',
            'site' => 'https://example.com',
            'tags' => ['a', 'b'],
            'meta' => ['page' => 1],
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('number', $schema['properties']['score']['type']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertSame('email', $schema['properties']['email']['format']);
        $this->assertSame('date-time', $schema['properties']['created_at']['format']);
        $this->assertSame('uri', $schema['properties']['site']['format']);
        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame('string', $schema['properties']['tags']['items']['type']);
        $this->assertSame('object', $schema['properties']['meta']['type']);
        $this->assertSame([1], $schema['properties']['id']['examples']);
    }

    #[Test]
    public function it_stops_at_a_sensible_depth(): void
    {
        $payload = 'leaf';

        for ($i = 0; $i < 20; $i++) {
            $payload = ['child' => $payload];
        }

        $this->assertIsArray($this->factory->fromExample($payload));
    }
}
