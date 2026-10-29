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
 * The Notion Settings page's sync buttons ride the shared NotionSyncAction:
 * "Sync now" carries the full-sync checkbox (normal sync by default), and
 * "Preview" runs the read-only dry run of the full sync.
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
            ->callAction('syncNow')
            ->assertNotified('No new issues.');
    }

    public function test_sync_now_full_syncs_when_the_checkbox_is_ticked(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true)
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callAction('syncNow', data: ['restoreDeleted' => true])
            ->assertNotified('Imported 1 issue(s).');
    }

    public function test_the_preview_button_runs_the_read_only_dry_run(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(true, true)
            ->andReturn(['status' => 'ok', 'message' => 'Would import 2 issue(s); 1 already synced/duplicate/blank.', 'created' => 2, 'skipped' => 1, 'preview' => "- Brand new row (2026-07-01, P3)\n- Duplicate title (already in the dashboard): Already there"]);

        $this->settingsPage()
            ->callAction('previewSync')
            ->assertNotified();
    }

    private function settingsPage()
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo('manage api tokens');

        return Livewire::actingAs($user)->test(NotionSettings::class);
    }
}
