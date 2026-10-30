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
 * carries the full-sync checkbox (normal sync by default) and "Preview"
 * opens a centered modal with the read-only dry run of the full sync —
 * Close, or "Continue full sync" (owner 2026-10-30). They are form-component
 * actions — the header holds only the connection buttons.
 */
class NotionSettingsSyncButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_now_defaults_to_a_normal_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, false)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow')
            ->assertNotified('No new issues.');
    }

    public function test_sync_now_full_syncs_when_the_checkbox_is_ticked(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true)
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow', data: ['restoreDeleted' => true])
            ->assertNotified('Imported 1 issue(s).');
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
            ->assertSee('Continue full sync')
            ->assertSee('Close');
    }

    public function test_continuing_from_the_preview_modal_runs_the_real_full_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            // Mounting the modal renders its content — the dry run — before
            // the submit runs the real thing.
            ->shouldReceive('sync')->once()->with(true, true)
            ->andReturn(['status' => 'ok', 'message' => 'Would import 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null])
            ->shouldReceive('sync')->once()->with(false, true)
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('previewSyncAction', 'previewSync')
            ->assertNotified('Imported 1 issue(s).');
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
