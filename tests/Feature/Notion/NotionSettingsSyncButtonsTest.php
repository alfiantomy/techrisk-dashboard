<?php

namespace Tests\Feature\Notion;

use App\Filament\Pages\NotionSettings;
use App\Models\User;
use App\Services\Notion\NotionIssueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Notion Settings page's sync buttons live INSIDE the "Sync issue from
 * Notion" section (owner 2026-10-29), not in the page header: "Sync now"
 * carries the full-sync checkbox plus the restore picker (owner 2026-10-30 —
 * only the ticked deleted issues come back) and "Preview" opens a centered
 * modal with the read-only dry run of the full sync — Close only, no run
 * from the preview (the picker is the only full-sync path, owner
 * 2026-10-30). They are form-component actions — the header holds only the
 * connection buttons.
 */
class NotionSettingsSyncButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_now_defaults_to_a_normal_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([])
            ->shouldReceive('sync')->once()->with(false, false, null)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow')
            ->assertNotified('No new issues.');
    }

    public function test_sync_now_full_sync_restores_only_the_picked_issues(): void
    {
        // The options must be non-empty for the checkbox list to render —
        // a hidden (unrendered) picker dehydrates nothing.
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([
                'aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)',
            ])
            ->shouldReceive('sync')->once()->with(false, true, ['aaaa556e44dd81c395b0c542f2599aaa'])
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow', data: [
                'restoreDeleted' => true,
                'restorePageIds' => ['aaaa556e44dd81c395b0c542f2599aaa'],
            ])
            ->assertNotified('Imported 1 issue(s).');
    }

    public function test_sync_now_full_sync_with_an_empty_pick_restores_nothing(): void
    {
        // Ticking full sync and picking nothing is a deliberate
        // restore-nothing — new rows still import (owner 2026-10-30).
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([])
            ->shouldReceive('sync')->once()->with(false, true, [])
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow', data: ['restoreDeleted' => true])
            ->assertNotified('No new issues.');
    }

    public function test_the_full_sync_picker_lists_the_restorable_deleted_issues(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->once()->andReturn([
                'aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)',
            ]);

        $this->settingsPage()
            ->mountFormComponentAction('syncNowAction', 'syncNow')
            ->setFormComponentActionData(['restoreDeleted' => true])
            ->assertFormComponentActionMounted('syncNowAction', ['syncNow'])
            ->assertSee('Deleted issues to restore')
            ->assertSee('DB down (2026-01-12, P2)');
    }

    public function test_the_preview_button_mounts_a_modal_with_the_dry_run_results(): void
    {
        // Owner 2026-10-30: Preview opens a centered modal showing the dry
        // run — not a toast. The dry run itself happens when the modal opens.
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(true, true)
            ->andReturn(['status' => 'ok', 'message' => 'Would import 1 issue(s); 1 duplicate title(s).', 'created' => 1, 'skipped' => 1, 'preview' => "- Brand new row (2026-07-01, P3)\n- Duplicate title (already in the dashboard): Already there"]);

        $this->settingsPage()
            ->mountFormComponentAction('previewSyncAction', 'previewSync')
            ->assertFormComponentActionMounted('previewSyncAction', ['previewSync'])
            ->assertSee('Would import 1 issue(s); 1 duplicate title(s).')
            ->assertSee('Brand new row (2026-07-01, P3)')
            ->assertSee('Close')
            // The preview never runs the sync — the restore picker owns the
            // real full sync (owner 2026-10-30).
            ->assertDontSee('Continue full sync');
    }

    public function test_the_sync_buttons_are_not_page_header_actions(): void
    {
        $this->settingsPage()
            ->assertActionDoesNotExist('syncNow')
            ->assertActionDoesNotExist('previewSync');
    }

    private function settingsPage()
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo('manage api tokens');

        return Livewire::actingAs($user)->test(NotionSettings::class);
    }
}
