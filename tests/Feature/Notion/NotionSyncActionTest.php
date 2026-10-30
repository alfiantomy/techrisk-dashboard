<?php

namespace Tests\Feature\Notion;

use App\Filament\Actions\NotionSyncAction;
use App\Services\Notion\NotionIssueSyncService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The shared sync runner: "Sync now" on both surfaces passes the full-sync
 * flag and the restore pick through to the service. The Preview button opens
 * a MODAL (owner 2026-10-30): the read-only dry run of the full sync renders
 * there — one finding per line, scrollable — with Close in the footer (the
 * real run goes through "Sync now" and its restore picker, owner
 * 2026-10-30); it is no longer a toast.
 */
class NotionSyncActionTest extends TestCase
{
    public function test_run_defaults_to_a_normal_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, false, null)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run();

        $this->assertSame('No new issues.', $this->sentToasts()->first()['title']);
    }

    public function test_run_passes_the_full_sync_flag_to_the_service(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true, null)
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run(restoreDeleted: true);

        $this->assertSame('Imported 1 issue(s).', $this->sentToasts()->first()['title']);
    }

    public function test_run_passes_the_restore_pick_to_the_service(): void
    {
        // The picker's selection — even an empty one — is the operator's
        // explicit pick; only null (no picker involved) means restore-all.
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true, ['aaaa556e44dd81c395b0c542f2599aaa'])
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run(restoreDeleted: true, restorePageIds: ['aaaa556e44dd81c395b0c542f2599aaa']);

        $this->assertSame('Imported 1 issue(s).', $this->sentToasts()->first()['title']);
    }

    public function test_the_preview_modal_content_is_the_read_only_full_dry_run(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(true, true)
            ->andReturn([
                'status' => 'ok',
                'message' => 'Would import 2 issue(s); 1 already synced; 1 duplicate title(s).',
                'created' => 2,
                'skipped' => 2,
                'preview' => implode("\n", array_map(fn (int $i) => "- Row {$i}", range(1, 45))),
            ]);

        $html = (string) NotionSyncAction::previewModalContent();

        // The summary carries the per-reason breakdown; every finding gets
        // its own line, uncapped — the modal scrolls.
        $this->assertStringContainsString('Would import 2 issue(s); 1 already synced; 1 duplicate title(s).', $html);
        $this->assertStringContainsString('Row 1', $html);
        $this->assertStringContainsString('Row 45', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);
    }

    public function test_the_preview_modal_marks_failed_rows(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(true, true)
            ->andReturn([
                'status' => 'partial',
                'message' => 'Would import 0 issue(s); 1 failed.',
                'created' => 0,
                'skipped' => 0,
                'preview' => "FAILED: Broken row — title too long\n- Good row (2026-07-01, P3)",
            ]);

        $html = (string) NotionSyncAction::previewModalContent();

        $this->assertStringContainsString('text-danger-600', $html);
        $this->assertStringContainsString('FAILED: Broken row — title too long', $html);
    }

    public function test_the_shared_sync_modal_carries_the_full_sync_checkbox_and_warning(): void
    {
        $form = NotionSyncAction::form();

        $this->assertCount(4, $form);

        $checkbox = $form[0];

        $this->assertInstanceOf(Checkbox::class, $checkbox);
        $this->assertSame('restoreDeleted', $checkbox->getName());
        $this->assertTrue($checkbox->isLive());

        $warning = $form[1];

        $this->assertInstanceOf(Placeholder::class, $warning);
        $this->assertSame('full_sync_warning', $warning->getName());

        // The restore picker (owner 2026-10-30) and its empty state — both
        // hidden until the full-sync checkbox is ticked.
        $picker = $form[2];

        $this->assertInstanceOf(CheckboxList::class, $picker);
        $this->assertSame('restorePageIds', $picker->getName());

        $empty = $form[3];

        $this->assertInstanceOf(Placeholder::class, $empty);
        $this->assertSame('restore_picker_empty', $empty->getName());
    }

    private function sentToasts(): Collection
    {
        return collect(session('filament.notifications', []));
    }
}
