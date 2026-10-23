<?php

namespace Tests\Feature\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\Notion\NotionIssueSyncService;
use App\Services\Notion\NotionMcpClient;
use App\Services\Notion\NotionSyncedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotionIssueSyncServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_imports_new_sub_pages_as_issues(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([$this->entry('page-1', 'Summary of Incident - DB down', 'January 12, 2026', 'Infrastructure Issue')]);

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

        $this->assertTrue(NotionSyncedPage::where('page_id', 'page-1')->where('incident_id', $incident->id)->exists());
        $this->assertNotNull(Setting::get(NotionIssueSyncService::LAST_SYNC_AT_KEY));
    }

    public function test_second_run_does_not_duplicate(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([$this->entry('page-1', 'DB down', 'January 12, 2026', 'Code Bug')], times: 2);

        app(NotionIssueSyncService::class)->sync();
        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, Incident::count());
        $this->assertSame(1, NotionSyncedPage::count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([$this->entry('page-1', 'DB down', 'January 12, 2026', 'Code Bug')]);

        $result = app(NotionIssueSyncService::class)->sync(dryRun: true);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['created']);
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

    public function test_unparseable_fetch_response_reports_raw_shape_for_inspection(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mock(NotionMcpClient::class)->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->once()->andReturn([
                'structured' => null,
                'text' => 'Here is what I found, in prose.',
                'isError' => false,
            ]);

        $result = app(NotionIssueSyncService::class)->sync();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Here is what I found, in prose.', $result['preview']);
    }

    public function test_unmapped_severity_defaults_to_g(): void
    {
        Setting::set(NotionIssueSyncService::WATCH_KEY, 'db-1');
        $this->mockConnectedNotion([$this->entry('page-2', 'New kind of problem', 'February 2, 2026', 'Never Seen Before')]);

        app(NotionIssueSyncService::class)->sync();

        $this->assertSame('G', Incident::query()->sole()->severity->value);
    }

    private function mockConnectedNotion(array $entries, int $times = 1): void
    {
        $this->mock(NotionMcpClient::class)
            ->shouldReceive('connected')->andReturn(true)
            ->shouldReceive('callToolDetailed')->times($times)->with('notion-fetch', ['id' => 'db-1'])
            ->andReturn([
                'structured' => ['results' => $entries],
                'text' => '',
                'isError' => false,
            ]);
    }

    private function entry(string $pageId, string $title, string $date, string $classification): array
    {
        return [
            'id' => $pageId,
            'created_time' => '2026-01-12T00:00:00.000Z',
            'properties' => [
                'Name' => ['type' => 'title', 'title' => [['plain_text' => $title]]],
                'Date of Incident' => ['type' => 'date', 'date' => ['start' => $date]],
                'Root Cause Classification' => ['type' => 'select', 'select' => ['name' => $classification]],
            ],
        ];
    }
}
