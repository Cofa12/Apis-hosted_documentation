<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\Data\Parameter;
use Cofa\ApiDocs\Support\ParameterTree;
use Cofa\ApiDocs\Support\ValidationRuleParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParameterTreeTest extends TestCase
{
    #[Test]
    public function it_leaves_flat_parameters_alone(): void
    {
        $nested = ParameterTree::nest([new Parameter('name'), new Parameter('email')]);

        $this->assertSame(['name', 'email'], array_map(fn ($p) => $p->name, $nested));
    }

    #[Test]
    public function it_nests_dotted_names_under_a_parent_object(): void
    {
        $nested = ParameterTree::nest([
            new Parameter('address', 'array', true),
            new Parameter('address.city', 'string', true),
            new Parameter('address.zip', 'string'),
        ]);

        $this->assertCount(1, $nested);
        $this->assertSame('address', $nested[0]->name);
        $this->assertSame('object', $nested[0]->type, 'A parent with named children is an object.');
        $this->assertSame(['city', 'zip'], array_map(fn ($p) => $p->name, $nested[0]->children));
    }

    #[Test]
    public function it_creates_missing_parents(): void
    {
        $nested = ParameterTree::nest([new Parameter('meta.seo.title', 'string')]);

        $this->assertSame('meta', $nested[0]->name);
        $this->assertSame('object', $nested[0]->type);
        $this->assertSame('seo', $nested[0]->children[0]->name);
        $this->assertSame('title', $nested[0]->children[0]->children[0]->name);
    }

    #[Test]
    public function a_wildcard_describes_the_items_of_its_parent(): void
    {
        $nested = ParameterTree::nest([
            new Parameter('tags', 'array'),
            new Parameter('tags.*', 'string'),
        ]);

        $this->assertCount(1, $nested);
        $this->assertSame('string[]', $nested[0]->type);
    }

    #[Test]
    public function a_wildcard_with_children_becomes_a_list_of_objects(): void
    {
        $nested = ParameterTree::nest([
            new Parameter('rows', 'array'),
            new Parameter('rows.*.sku', 'string', true),
            new Parameter('rows.*.qty', 'integer', true),
        ]);

        $this->assertSame('object[]', $nested[0]->type);
        $this->assertSame(['sku', 'qty'], array_map(fn ($p) => $p->name, $nested[0]->children));
    }

    #[Test]
    public function it_round_trips_through_flatten(): void
    {
        $rules = [
            'title' => 'required|string',
            'rows' => 'array',
            'rows.*.sku' => 'required|string',
            'address.city' => 'required|string',
        ];

        $nested = ParameterTree::nest((new ValidationRuleParser())->parse($rules));
        $flat = array_map(fn ($p) => $p->name, ParameterTree::flatten($nested));

        $this->assertContains('title', $flat);
        $this->assertContains('rows.*.sku', $flat);
        $this->assertContains('address.city', $flat);
    }

    #[Test]
    public function it_merges_duplicate_definitions(): void
    {
        $first = new Parameter('name', 'string', true, 'From the rules.');
        $second = new Parameter('name', 'string', false, '', 'Ada');

        $nested = ParameterTree::nest([$first, $second]);

        $this->assertCount(1, $nested);
        $this->assertTrue($nested[0]->required);
        $this->assertSame('From the rules.', $nested[0]->description);
        $this->assertSame('Ada', $nested[0]->example);
    }
}
