<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\ActionImprovementsRelationManager;
use App\Filament\Widgets\RootCauseFundParetoChart;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PROJ-011 Wave 3: the root-cause Pareto (widget-aligned fund scope,
 * multi-category loss split) and the overdue indicator on the Action
 * Improvements relation manager (RD-3).
 */
class RootCauseFundParetoChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function completedLoss(array $attrs = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_status' => 'Completed',
            'fund_status' => 'Confirmed loss',
            'fund_loss' => 1000,
            'incident_date' => now()->subDays(10)->setTime(9, 0),
            'stop_bleeding_at' => now()->subDays(10)->setTime(10, 0),
            'root_cause' => 'rc',
        ], $attrs));
    }

    /** @return list<array{category: string, loss: float, cumulative_pct: float}> */
    private function paretoRows(): array
    {
        return (new \ReflectionMethod(new RootCauseFundParetoChart, 'pareto'))
            ->invoke(new RootCauseFundParetoChart);
    }

    public function test_pareto_splits_multi_category_loss_and_orders_desc(): void
    {
        $this->completedLoss(['root_cause_category' => ['Fraud'], 'fund_loss' => 600]);
        $this->completedLoss(['root_cause_category' => ['Fraud', 'System Bug'], 'fund_loss' => 200]); // 100 each
        $this->completedLoss(['root_cause_category' => ['Human Error'], 'fund_loss' => 300]);

        $rows = $this->paretoRows();

        $this->assertSame('Fraud', $rows[0]['category']);
        $this->assertSame(700.0, $rows[0]['loss']);
        $this->assertSame(63.6, $rows[0]['cumulative_pct']); // 700/1100
        $this->assertSame('Human Error', $rows[1]['category']);
        $this->assertSame(90.9, $rows[1]['cumulative_pct']); // 1000/1100
        // Bars sum to the total actual loss — nothing double-counted.
        $this->assertSame(1100.0, array_sum(array_column($rows, 'loss')));
    }

    public function test_pareto_keeps_uncategorized_loss_visible(): void
    {
        $this->completedLoss(['root_cause_category' => null]);

        $rows = $this->paretoRows();

        $this->assertSame('(uncategorized)', $rows[0]['category']);
        $this->assertSame(1000.0, $rows[0]['loss']);
    }

    public function test_pareto_scope_is_widget_aligned(): void
    {
        // Open row (not Completed) and fund-status-excluded row don't count;
        // severity G never counts.
        $this->completedLoss(['root_cause_category' => ['Fraud']]);
        $this->completedLoss(['incident_status' => 'Open', 'root_cause_category' => ['Fraud']]);
        $this->completedLoss(['fund_status' => 'Fully recovered', 'root_cause_category' => ['Fraud']]);
        $this->completedLoss(['severity' => 'G', 'root_cause_category' => ['Fraud']]);

        $rows = $this->paretoRows();

        $this->assertCount(1, $rows);
        $this->assertSame(1000.0, $rows[0]['loss']);
    }

    public function test_overdue_pending_action_item_is_flagged_in_the_relation_manager(): void
    {
        $user = User::factory()->create();
        $incident = $this->completedLoss();
        $overdue = ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'status' => 'pending',
            'due_date' => today()->subDays(4),
        ]);
        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'status' => 'done',
            'due_date' => today()->subDays(4), // past due but done — not flagged
        ]);
        ActionImprovement::factory()->create([
            'incident_id' => $incident->id,
            'status' => 'pending',
            'due_date' => today()->addDays(4), // pending, not due yet
        ]);

        Livewire::actingAs($user)
            ->test(ActionImprovementsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->assertSuccessful()
            ->assertSee('overdue');

        $this->assertSame('pending', $overdue->fresh()->status); // display-only change
    }
}
