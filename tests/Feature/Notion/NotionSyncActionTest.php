<?php

namespace Tests\Feature\Notion;

use App\Filament\Actions\NotionSyncAction;
use App\Services\Notion\NotionIssueSyncService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The shared sync runner: "Sync now" on both surfaces passes the full-sync
 * flag through to the service, and the Preview button runs a READ-ONLY dry
 * run of the full sync with the itemized preview in the notification.
 * Outside a Livewire component the toast lands in the session (the same
 * surface NotionOauthRoutesTest asserts on).
 */
class NotionSyncActionTest extends TestCase
{
    public function test_run_defaults_to_a_normal_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, false)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run();

        $this->assertSame('No new issues.', $this->sentToasts()->first()['title']);
    }

    public function test_run_passes_the_full_sync_flag_to_the_service(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true)
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run(restoreDeleted: true);

        $this->assertSame('Imported 1 issue(s).', $this->sentToasts()->first()['title']);
    }

    public function test_preview_runs_a_read_only_full_dry_run(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(true, true)
            ->andReturn([
                'status' => 'ok',
                'message' => 'Would import 2 issue(s); 1 already synced/duplicate/blank.',
                'created' => 2,
                'skipped' => 1,
                'preview' => implode("\n", array_map(fn (int $i) => "- Row {$i}", range(1, 45))),
            ]);

        NotionSyncAction::preview();

        $toast = $this->sentToasts()->first();
        $body = (string) ($toast['body'] ?? '');

        $this->assertStringContainsString('dry run', (string) $toast['title']);
        $this->assertStringContainsString('Would import 2 issue(s); 1 already synced/duplicate/blank.', $body);
        $this->assertStringContainsString('- Row 40', $body);
        $this->assertStringContainsString('… and 5 more', $body);
        $this->assertStringNotContainsString('- Row 41'.PHP_EOL, $body);
    }

    public function test_the_shared_sync_modal_carries_the_full_sync_checkbox_and_warning(): void
    {
        $form = NotionSyncAction::form();

        $this->assertCount(2, $form);

        $checkbox = $form[0];

        $this->assertInstanceOf(Checkbox::class, $checkbox);
        $this->assertSame('restoreDeleted', $checkbox->getName());
        $this->assertTrue($checkbox->isLive());

        $warning = $form[1];

        $this->assertInstanceOf(Placeholder::class, $warning);
        $this->assertSame('full_sync_warning', $warning->getName());
    }

    private function sentToasts(): Collection
    {
        return collect(session('filament.notifications', []));
    }
}
