<?php

namespace Cofa\ApiDocs\Tests\Feature;

use Cofa\ApiDocs\History\Change;
use Cofa\ApiDocs\History\HistoryStore;
use Cofa\ApiDocs\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class EndpointHistoryTest extends TestCase
{
    protected string $output = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir() . '/api-docs-history-' . bin2hex(random_bytes(4));

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

    protected function store(): HistoryStore
    {
        return $this->app->make(HistoryStore::class);
    }

    /**
     * Run a command and hand back everything it printed.
     *
     * The expectsOutputToContain() helper matches one line against a single
     * expectation, which makes it unusable when several expectations would
     * match the same line.
     */
    protected function runCommand(string $command): string
    {
        $this->assertSame(0, Artisan::call($command));

        return Artisan::output();
    }

    /** Register an extra endpoint so the next scan differs from the last one. */
    protected function addEndpoint(): void
    {
        Route::middleware('api')->prefix('api')->group(function () {
            Route::post('webhooks', function (\Illuminate\Http\Request $request) {
                $request->validate(['url' => 'required|url', 'events' => 'required|array']);

                return response()->json(['queued' => true], 202);
            });
        });
    }

    #[Test]
    public function the_first_generate_records_the_baseline(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->assertFileExists($this->output . '/history.json');

        $history = $this->store()->load();

        $this->assertSame(1, $history->count());

        $revision = $history->latest()[0];

        $this->assertTrue($revision->initial);
        $this->assertSame('rev-1', $revision->id());
        $this->assertSame('2.4.0', $revision->version);
        $this->assertGreaterThan(5, $revision->count());
        $this->assertStringContainsString('endpoints documented', $revision->headline());
        $this->assertNotSame([], $history->snapshot, 'The snapshot is kept for the next comparison.');
    }

    #[Test]
    public function generating_again_with_no_changes_records_nothing(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->artisan('api-docs:generate')
            ->expectsOutputToContain('no changes since the last run')
            ->assertSuccessful();

        $this->assertSame(1, $this->store()->load()->count());
    }

    #[Test]
    public function a_new_endpoint_is_recorded_as_a_revision(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->addEndpoint();

        $this->artisan('api-docs:generate')->assertSuccessful();

        $history = $this->store()->load();
        $this->assertSame(2, $history->count());

        $revision = $history->latest()[0];

        $this->assertFalse($revision->initial);
        $this->assertSame('rev-2', $revision->id());
        $this->assertSame('1 added', $revision->headline());

        $added = $revision->added()[0];
        $this->assertSame('POST', $added->method);
        $this->assertSame('/api/webhooks', $added->path);
        $this->assertSame('Endpoint added', $added->changes[0]->summary);
    }

    #[Test]
    public function the_history_of_a_single_endpoint_can_be_looked_up(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();
        $this->addEndpoint();
        $this->artisan('api-docs:generate')->assertSuccessful();

        $entries = $this->store()->load()->forOperation('POST', '/api/webhooks');

        $this->assertCount(1, $entries);
        $this->assertSame(2, $entries[0]['revision']->number);
        $this->assertSame(Change::ADDED, $entries[0]['operation']->type);

        // An untouched endpoint only carries its baseline entry.
        $this->assertCount(1, $this->store()->load()->forOperation('GET', '/api/users'));
    }

    #[Test]
    public function the_history_can_be_switched_off(): void
    {
        $this->artisan('api-docs:generate --no-history')->assertSuccessful();

        $this->assertFileDoesNotExist($this->output . '/history.json');

        $this->withConfig(['api-docs.history.enabled' => false]);
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->assertFileDoesNotExist($this->output . '/history.json');
    }

    #[Test]
    public function old_revisions_are_pruned(): void
    {
        $this->withConfig(['api-docs.history.keep' => 2]);

        $this->artisan('api-docs:generate')->assertSuccessful();

        foreach (['alpha', 'beta', 'gamma'] as $name) {
            Route::middleware('api')->prefix('api')->group(function () use ($name) {
                Route::get('extra/' . $name, fn () => []);
            });

            $this->artisan('api-docs:generate')->assertSuccessful();
        }

        $history = $this->store()->load();

        $this->assertSame(2, $history->count());
        $this->assertSame([4, 3], array_map(fn ($r) => $r->number, $history->latest()));
    }

    #[Test]
    public function a_corrupted_history_file_does_not_break_the_docs(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        File::put($this->output . '/history.json', 'not json at all');

        $this->assertTrue($this->store()->load()->isEmpty());
        $this->get('/api/documentation')->assertOk();
    }

    #[Test]
    public function the_page_shows_the_changelog_and_the_endpoint_history(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();
        $this->addEndpoint();
        $this->artisan('api-docs:generate')->assertSuccessful();

        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringContainsString('id="changelog"', $html);
        $this->assertStringContainsString('Recent changes', $html);
        $this->assertStringContainsString('rev-2', $html);
        $this->assertStringContainsString('class="chg chg-added"', $html);
        $this->assertStringContainsString('href="#post-api-webhooks"', $html);

        $this->assertStringContainsString('>History<', $html, 'Each endpoint carries its own timeline.');
        $this->assertStringContainsString('Documented for the first time', $html);
        $this->assertStringContainsString('Updated ' . date('Y-m-d'), $html, 'The card shows when it last changed.');
    }

    #[Test]
    public function the_history_can_be_hidden_from_the_page(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $this->withConfig(['api-docs.history.show_in_ui' => false]);

        $html = $this->get('/api/documentation')->getContent();

        $this->assertStringNotContainsString('id="changelog"', $html);
        $this->assertStringNotContainsString('Documented for the first time', $html);
    }

    #[Test]
    public function the_history_command_prints_the_timeline(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();
        $this->addEndpoint();
        $this->artisan('api-docs:generate')->assertSuccessful();

        $output = $this->runCommand('api-docs:history');

        $this->assertStringContainsString('rev-2', $output);
        $this->assertStringContainsString('rev-1', $output);
        $this->assertStringContainsString('1 added', $output);
        $this->assertStringContainsString('Added    POST /api/webhooks', $output);
        $this->assertStringContainsString('Endpoint added', $output);
        $this->assertStringContainsString('9 endpoints documented', $output);
    }

    #[Test]
    public function the_history_command_filters_by_endpoint(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();
        $this->addEndpoint();
        $this->artisan('api-docs:generate')->assertSuccessful();

        $filtered = $this->runCommand('api-docs:history --endpoint=webhooks');

        $this->assertStringContainsString('/api/webhooks', $filtered);
        $this->assertStringNotContainsString('/api/users', $filtered);

        $this->assertStringContainsString(
            'No revisions matched',
            $this->runCommand('api-docs:history --endpoint=nothing-matches-this')
        );
    }

    #[Test]
    public function the_history_command_says_when_there_is_nothing_yet(): void
    {
        $this->assertStringContainsString('No history recorded yet', $this->runCommand('api-docs:history'));
    }

    #[Test]
    public function the_history_command_can_emit_json(): void
    {
        $this->artisan('api-docs:generate')->assertSuccessful();

        $json = json_decode($this->runCommand('api-docs:history --json'), true);

        $this->assertSame(1, $json['version']);
        $this->assertCount(1, $json['revisions']);
        $this->assertArrayHasKey('snapshot', $json);
    }
}
