<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\RiskHeatMatrixWidget;
use App\Models\User;
use App\Support\PanelHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `view dashboard widgets` grants read-only access to the Dashboard page and
 * the Risk Heat Matrix widget without `manage incidents`. The rule lives in
 * User::canViewDashboardWidgets() — these tests pin every surface that must
 * agree: the page gate, the widget gate, and the PanelHome landing URL.
 */
class DashboardWidgetPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $permission): User
    {
        Permission::firstOrCreate(['name' => $permission]);

        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    public function test_dashboard_accessible_with_view_widgets_permission(): void
    {
        $this->actingAs($this->userWith('view dashboard widgets'));

        Livewire::test(Dashboard::class)->assertSuccessful();
    }

    public function test_dashboard_forbidden_without_widget_permissions(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)->assertForbidden();
    }

    public function test_risk_heat_matrix_viewable_with_view_widgets_permission(): void
    {
        $this->actingAs($this->userWith('view dashboard widgets'));

        $this->assertTrue(RiskHeatMatrixWidget::canView());
    }

    public function test_risk_heat_matrix_hidden_without_widget_permissions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(RiskHeatMatrixWidget::canView());
    }

    public function test_panel_home_lands_widget_viewer_on_dashboard(): void
    {
        $user = $this->userWith('view dashboard widgets');

        $this->assertSame(
            route('filament.admin.pages.dashboard'),
            PanelHome::urlFor($user),
        );
    }

    public function test_panel_home_still_lands_plain_user_on_incidents_list(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            route('filament.admin.resources.incidents.index'),
            PanelHome::urlFor($user),
        );
    }
}
