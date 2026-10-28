<?php

namespace Tests\Feature\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\NotionSyncedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotionIssueSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const COLLECTION_URL = 'collection://2c72556e-44dd-81cf-8977-000bc43d6f86';

    public function test_fails_without_a_watch_target(): void
    {
        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('watch target', $result['message']);
    }

    public function test_fails_when_notion_is_not_connected(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');

        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->once()->andReturn(false);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not connected', $result['message']);
    }

    public function test_imports_new_database_rows_as_issues(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'Summary of Incident - DB down', '2026-01-12', 'P1', 'Infrastructure (Database)'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);

        $incident = Incident::query()->sole();

        $this->assertSame('DB down', $incident->title);
        $this->assertSame('2026-01-12', $incident->incident_date->toDateString());
        $this->assertSame('P1', $incident->severity->value);
        $this->assertSame(IncidentClassification::Issue->value, $incident->classification->value);
        $this->assertNotNull($incident->no);
        $this->assertSame('2026-01-12', $incident->entry_date_tech_risk->toDateString());

        $this->assertTrue(NotionSyncedPage::where('page_id', '4062556e44dd81c395b0c542f2599858')->where('incident_id', $incident->id)->exists());
        $this->assertNotNull(Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY));
        $this->assertSame('Imported 1 issue(s).', $result['message']);
    }

    public function test_classification_column_wins_over_root_cause_mapping(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            // Root cause "Deployment Issues" maps to P1 — the explicit P3 wins.
            $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P3', 'Deployment Issues'),
        ]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('P3', Incident::query()->sole()->severity->value);
    }

    public function test_second_run_does_not_duplicate_and_queries_since_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $row = $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug');

        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            // First run — no watermark yet: full backfill scan.
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => ($args['data']['data_source_urls'][0] ?? null) === self::COLLECTION_URL
                    && ! str_contains($args['data']['query'] ?? '', 'WHERE')
                    && str_contains($args['data']['query'] ?? '', 'ORDER BY createdTime ASC')
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false])
            // Second run — reads only rows created at/after the first run's newest row.
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(
                fn (array $args) => str_contains($args['data']['query'] ?? '', "WHERE createdTime >= '2026-01-12 00:00:00Z'")
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => [$row]]), 'isError' => false]);

        app(NotionIssueSyncService::class)->sync();
        $result = app(NotionIssueSyncService::class)->sync();

        // The re-fetched row shares the watermark's second — an expected
        // boundary re-read, not a finding, so the run reports nothing.
        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('No new issues.', $result['message']);
        $this->assertSame(1, Incident::count());
        $this->assertSame(1, NotionSyncedPage::count());
    }

    public function test_same_second_straggler_at_the_boundary_still_imports(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-07-01 00:00:00Z');
        $this->mockConnectedNotion([
            // createdTime equals the watermark but the page is new — a
            // same-second straggler the previous run's query missed. The
            // boundary flag must never suppress the import itself.
            $this->row('cccc556e44dd81c395b0c542f2599ccc', 'Boundary straggler', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
        ], expectWatermark: '2026-07-01 00:00:00Z');

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('Boundary straggler', Incident::sole()->title);
    }

    public function test_incremental_run_reads_only_rows_since_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');
        $this->mockConnectedNotion([
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Only brand new row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
        ], expectWatermark: '2026-06-01 00:00:00Z');

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('2026-07-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_watermark_advances_to_the_newest_row_seen_including_skipped_ones(): void
    {
        Incident::create([
            'title' => 'Older issue',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('aaaa556e44dd81c395b0c542f2599aaa', 'Fresh row', '2026-07-01', 'P3', 'Data Issue', createdTime: '2026-07-01 00:00:00Z'),
            // Skipped (title matches an existing issue) but still SEEN — the
            // watermark must move past it so it is never re-read.
            $this->row('bbbb556e44dd81c395b0c542f2599bbb', 'Older issue', '2026-08-01', 'P2', 'Code Bug', createdTime: '2026-08-01 00:00:00Z'),
        ]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('2026-08-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
        $this->assertSame(2, Incident::count());
    }

    public function test_dry_run_does_not_advance_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug'),
        ]);

        app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertNull(Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_failed_sync_keeps_the_watermark(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        Setting::set(NotionIssueSyncService::WATERMARK_KEY, '2026-06-01 00:00:00Z');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn(null);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('2026-06-01 00:00:00Z', Setting::get(NotionIssueSyncService::WATERMARK_KEY));
    }

    public function test_rows_already_imported_by_title_are_skipped(): void
    {
        Incident::create([
            'title' => 'DB down',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'Summary of Incident - DB down', '2026-01-12', 'P1', 'Infrastructure (Database)'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Imported 0 issue(s); 1 already synced/duplicate/blank.', $result['message']);
        $this->assertSame(1, Incident::count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'DB down', '2026-01-12', 'P2', 'Code Bug'),
        ]);

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
        $this->assertStringContainsString('DB down', (string) $result['preview']);
        $this->assertSame(0, Incident::count());
        $this->assertSame(0, NotionSyncedPage::count());
        $this->assertNull(Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY));
    }

    public function test_fetch_failure_leaves_database_untouched(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn(null);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, Incident::count());
        $this->assertStringContainsString('failed', (string) Setting::get(NotionIssueSyncService::LAST_SYNC_STATUS_KEY));
    }

    public function test_fetch_mcp_error_is_surfaced(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn([
                'structured' => null,
                'text' => 'Could not find page: db-1',
                'isError' => true,
            ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Notion fetch failed: Could not find page: db-1', $result['message']);
        $this->assertSame(0, Incident::count());
    }

    public function test_watch_target_that_is_not_a_database_reports_the_raw_shape(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn([
                'structured' => null,
                'text' => '# A plain page with no collection reference',
                'isError' => false,
            ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('not a Notion database', $result['message']);
        $this->assertSame('# A plain page with no collection reference', $result['preview']);
    }

    public function test_query_mcp_error_is_surfaced(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(fn ($args) => is_array($args['data'] ?? null)))
            ->andReturn(['structured' => null, 'text' => 'no such column: "Bogus"', 'isError' => true]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('query failed: no such column', $result['message']);
    }

    public function test_unparseable_query_response_reports_raw_shape_for_inspection(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->once()->with('notion-query-data-sources', Mockery::on(fn ($args) => is_array($args['data'] ?? null)))
            ->andReturn(['structured' => null, 'text' => 'Here is what I found, in prose.', 'isError' => false]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Here is what I found, in prose.', $result['preview']);
    }

    public function test_unmapped_severity_defaults_to_g(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([
            $this->row('4062556e44dd81c395b0c542f2599858', 'New kind of problem', '2026-02-02', '-', 'Never Seen Before'),
        ]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('G', Incident::query()->sole()->severity->value);
    }

    /**
     * Chain the two-call flow: notion-fetch returns the data-source document
     * carrying the collection URL, then notion-query-data-sources returns rows.
     * $expectWatermark asserts the query's incremental WHERE clause: null = no
     * WHERE (first run / full backfill), a string = rows since that createdTime.
     */
    private function mockConnectedNotion(array $rows, int $times = 1, ?string $expectWatermark = null): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->times($times)->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn(['structured' => null, 'text' => $this->fetchText(), 'isError' => false])
            ->shouldReceive('callToolDetailed')->times($times)->with('notion-query-data-sources', Mockery::on(
                function (array $args) use ($expectWatermark) {
                    $query = $args['data']['query'] ?? '';

                    if (($args['data']['data_source_urls'][0] ?? null) !== self::COLLECTION_URL
                        || ! str_contains($query, self::COLLECTION_URL)
                        || ! str_contains($query, 'ORDER BY createdTime ASC')) {
                        return false;
                    }

                    return $expectWatermark === null
                        ? ! str_contains($query, 'WHERE')
                        : str_contains($query, "WHERE createdTime >= '{$expectWatermark}'");
                }
            ))
            ->andReturn(['structured' => null, 'text' => json_encode(['results' => $rows]), 'isError' => false]);
    }

    /** Minimal excerpt of the real fetched data-source document. */
    private function fetchText(): string
    {
        return '<data-source url="{{'.self::COLLECTION_URL.'}}">'
            .'"name":"Summary of Incident 2026 (DB)","url":"'.self::COLLECTION_URL.'"'
            .'</data-source>';
    }

    /** One SQL-result row from notion-query-data-sources (live shape, 2026-10-27). */
    private function row(string $pageId, string $title, string $dateStart, string $classification, string $rootCause, string $createdTime = '2026-01-12 00:00:00Z'): array
    {
        return [
            'url' => 'https://app.notion.com/'.$pageId,
            'createdTime' => $createdTime,
            'Name' => $title,
            'date_start' => $dateStart,
            'classification' => $classification,
            'root_cause' => $rootCause,
        ];
    }
}
