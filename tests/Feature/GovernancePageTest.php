<?php

namespace Tests\Feature;

use App\Filament\Pages\GovernancePage;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GovernancePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('manage incidents');

        return $user;
    }

    public function test_page_renders_kpis_with_permission(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_status' => 'Completed', 'root_cause' => 'Test RCA', 'stop_bleeding_at' => now(),
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->subDays(3)->setTime(9, 15),
            'discovered_at' => now()->subDays(3)->setTime(9, 45),
        ]);

        $this->actingAs($this->admin());

        Livewire::test(GovernancePage::class)
            ->assertSee('Governance Scorecard')
            ->assertSee('Register Completeness')
            ->assertSee('RCA Within 5 Days')
            ->assertSee('Overdue Action Items')
            ->assertSee('Recurrence This Quarter')
            ->assertSee('FL Detection Lag')
            ->assertSee('FL Usable Timing')
            ->assertSee('FL Unknown Channel')
            ->assertSee('Placeholder Timestamps')
            ->assertSuccessful();
    }

    public function test_page_denied_without_permission(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(GovernancePage::class)->assertForbidden();
    }
}
