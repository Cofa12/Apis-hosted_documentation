<?php

namespace Cofa\ApiDocs\Tests\Unit;

use Cofa\ApiDocs\History\Change;
use Cofa\ApiDocs\History\History;
use Cofa\ApiDocs\History\OperationChange;
use Cofa\ApiDocs\History\Revision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HistoryTest extends TestCase
{
    protected function revision(int $number, string $date, array $operations = []): Revision
    {
        return new Revision($number, $date . 'T09:00:00+00:00', '1.0.0', $operations);
    }

    protected function operation(string $type, string $method, string $path, array $changes = []): OperationChange
    {
        return new OperationChange($type, $method, $path, $changes ?: [
            Change::modified('summary', 'summary', 'Summary changed'),
        ]);
    }

    #[Test]
    public function it_numbers_revisions_sequentially(): void
    {
        $history = new History();

        $this->assertSame(1, $history->nextNumber());

        $history->add($this->revision(1, '2026-01-01'));
        $this->assertSame(2, $history->nextNumber());
    }

    #[Test]
    public function it_lists_the_newest_revision_first(): void
    {
        $history = new History();
        $history->add($this->revision(1, '2026-01-01'));
        $history->add($this->revision(2, '2026-02-01'));

        $this->assertSame([2, 1], array_map(fn (Revision $r) => $r->number, $history->latest()));
        $this->assertSame([2], array_map(fn (Revision $r) => $r->number, $history->latest(1)));
    }

    #[Test]
    public function it_drops_the_oldest_revisions_beyond_the_limit(): void
    {
        $history = new History();

        for ($i = 1; $i <= 5; $i++) {
            $history->add($this->revision($i, '2026-01-0' . $i), keep: 3);
        }

        $this->assertSame([5, 4, 3], array_map(fn (Revision $r) => $r->number, $history->latest()));
        $this->assertSame(6, $history->nextNumber(), 'Numbering continues even after pruning.');
    }

    #[Test]
    public function it_finds_every_revision_touching_one_endpoint(): void
    {
        $history = new History();
        $history->add($this->revision(1, '2026-01-01', [
            $this->operation(Change::ADDED, 'GET', '/users'),
            $this->operation(Change::ADDED, 'POST', '/users'),
        ]));
        $history->add($this->revision(2, '2026-02-01', [
            $this->operation(Change::MODIFIED, 'POST', '/users'),
        ]));

        $entries = $history->forOperation('POST', '/users');

        $this->assertCount(2, $entries);
        $this->assertSame(2, $entries[0]['revision']->number, 'Newest first.');
        $this->assertSame(1, $entries[1]['revision']->number);

        $this->assertCount(1, $history->forOperation('GET', '/users'));
        $this->assertSame([], $history->forOperation('DELETE', '/users'));
    }

    #[Test]
    public function the_endpoint_lookup_is_case_insensitive_about_the_verb(): void
    {
        $history = new History();
        $history->add($this->revision(1, '2026-01-01', [$this->operation(Change::ADDED, 'GET', '/users')]));

        $this->assertCount(1, $history->forOperation('get', '/users'));
    }

    #[Test]
    public function it_reports_when_an_endpoint_last_changed(): void
    {
        $history = new History();
        $history->add($this->revision(1, '2026-01-01', [$this->operation(Change::ADDED, 'GET', '/users')]));
        $history->add($this->revision(2, '2026-03-04', [$this->operation(Change::MODIFIED, 'GET', '/users')]));

        $this->assertSame('2026-03-04T09:00:00+00:00', $history->lastChangedAt('GET', '/users'));
        $this->assertNull($history->lastChangedAt('GET', '/nope'));
    }

    #[Test]
    public function a_revision_summarises_what_it_contains(): void
    {
        $revision = $this->revision(3, '2026-02-01', [
            $this->operation(Change::ADDED, 'GET', '/a'),
            $this->operation(Change::ADDED, 'GET', '/b'),
            $this->operation(Change::MODIFIED, 'GET', '/c'),
            $this->operation(Change::REMOVED, 'GET', '/d'),
        ]);

        $this->assertSame('rev-3', $revision->id());
        $this->assertSame('2026-02-01', $revision->date());
        $this->assertSame('2 added, 1 changed, 1 removed', $revision->headline());
        $this->assertCount(2, $revision->added());
        $this->assertCount(1, $revision->modified());
        $this->assertCount(1, $revision->removed());
        $this->assertTrue($revision->isBreaking(), 'A removed endpoint is breaking.');
    }

    #[Test]
    public function the_initial_revision_reads_differently(): void
    {
        $revision = new Revision(1, '2026-01-01T00:00:00+00:00', '1.0.0', [
            $this->operation(Change::ADDED, 'GET', '/a'),
        ], initial: true);

        $this->assertSame('1 endpoint documented', $revision->headline());
    }

    #[Test]
    public function an_empty_history_says_so(): void
    {
        $history = new History();

        $this->assertTrue($history->isEmpty());
        $this->assertSame(0, $history->count());
        $this->assertSame([], $history->latest());
    }

    #[Test]
    public function it_round_trips_through_json(): void
    {
        $history = new History();
        $history->add($this->revision(1, '2026-01-01', [
            $this->operation(Change::MODIFIED, 'PUT', '/users/{user}', [
                Change::added('body', 'body.age', 'Added body field `age` (required)', ['type' => 'integer']),
                Change::modified('auth', 'auth', 'Now requires authentication', false, true),
            ]),
        ]));
        $history->snapshot = ['openapi' => '3.1.0'];

        $restored = History::fromArray(json_decode($history->toJson(), true));

        $this->assertEquals($history, $restored);
        $this->assertSame(['openapi' => '3.1.0'], $restored->snapshot);
        $this->assertTrue($restored->latest()[0]->operations[0]->isBreaking());
    }
}
