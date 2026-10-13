<?php

namespace Tests\Unit\Services;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Services\Governance\GovernanceKpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GovernanceKpiServiceTest extends TestCase
{
    use RefreshDatabase;

    private GovernanceKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new GovernanceKpiService;
    }

    private function incident(array $attrs = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_status' => 'Completed',
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->subDays(10)->setTime(9, 15),
            'discovered_at' => now()->subDays(10)->setTime(9, 45), // +30min lag
            'stop_bleeding_at' => now()->subDays(10)->setTime(10, 0),
            'root_cause' => 'helper rca',
        ], $attrs));
    }

    /** A Completed row that predates the closure gate — RCA/stop-bleed missing. */
    private function legacyRow(array $attrs = []): Incident
    {
        return Incident::factory()->createQuietly(array_merge([
            'severity' => 'P1',
            'classification' => 'Incident',
            'incident_status' => 'Completed',
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->subDays(10)->setTime(9, 15),
        ], $attrs));
    }

    public function test_register_completeness_counts_only_fully_documented_rows(): void
    {
        $complete = $this->incident();
        ActionImprovement::factory()->create(['incident_id' => $complete->id, 'status' => 'done']);

        $this->legacyRow(['root_cause' => 'bug in Y', 'stop_bleeding_at' => null]); // no stop-bleed
        $this->legacyRow(['root_cause' => null, 'stop_bleeding_at' => now()->subDays(10)->setTime(10, 0)]); // no RCA

        $this->assertSame(33.3, $this->service->registerCompletenessPct());
    }

    public function test_register_completeness_accepts_root_cause_category_when_root_cause_missing(): void
    {
        $row = $this->incident([
            'root_cause_category' => ['Software Bug'],
            'stop_bleeding_at' => now()->subDays(10)->setTime(10, 0),
        ]);
        ActionImprovement::factory()->create(['incident_id' => $row->id]);

        $this->assertSame(100.0, $this->service->registerCompletenessPct());
    }

    public function test_overdue_action_items_counts_pending_past_due_only(): void
    {
        $incident = $this->incident(['root_cause' => 'rc']);
        ActionImprovement::factory()->create(['incident_id' => $incident->id, 'status' => 'pending', 'due_date' => today()->subDay()]);
        ActionImprovement::factory()->create(['incident_id' => $incident->id, 'status' => 'pending', 'due_date' => today()]);
        ActionImprovement::factory()->create(['incident_id' => $incident->id, 'status' => 'done', 'due_date' => today()->subDays(3)]);

        $this->assertSame(1, $this->service->overdueActionItemCount());
    }

    public function test_recurrence_counts_is_recurring_rows_this_quarter(): void
    {
        $this->incident(['recurrence_data' => ['is_recurring' => true]]);
        $this->incident(['recurrence_data' => ['is_recurring' => false]]);
        $this->incident(['recurrence_data' => ['is_recurring' => true, 'foo' => 'bar']]);
        $this->incident(); // no recurrence data

        $this->assertSame(2, $this->service->recurrenceThisQuarterCount());
    }

    public function test_detection_lag_median_and_usable_timing(): void
    {
        // FL stream: lags 1h and 3h usable; one placeholder-time row (00:00)
        // yields NO lag at all — a derived 6h from a fake start is worse
        // than useless (RD-4), unless detection_delay_days is recorded.
        $this->incident([
            'fund_status' => 'Confirmed loss',
            'incident_date' => now()->subDays(5)->setTime(8, 0),
            'discovered_at' => now()->subDays(5)->setTime(9, 0), // +1h
        ]);
        $this->incident([
            'fund_status' => 'Confirmed loss',
            'incident_date' => now()->subDays(6)->setTime(8, 0),
            'discovered_at' => now()->subDays(6)->setTime(11, 0), // +3h
        ]);
        $this->incident([
            'fund_status' => 'Confirmed loss',
            'incident_date' => now()->subDays(7)->startOfDay(), // placeholder 00:00
            'discovered_at' => now()->subDays(7)->setTime(6, 0), // unusable start
        ]);

        $summary = $this->service->summary();

        $this->assertSame(2.0, $summary['fl_detection_lag_median_hours']); // median(1,3)
        $this->assertSame(66.7, $summary['fl_usable_timing_pct']); // 2 of 3 usable
        $this->assertSame(1, $summary['placeholder_time_count']);
    }

    public function test_even_count_median_is_average_of_middle_pair(): void
    {
        $this->incident([
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->subDays(2)->setTime(8, 0),
            'discovered_at' => now()->subDays(2)->setTime(8, 30), // 0.5h
        ]);
        $this->incident([
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->subDays(3)->setTime(8, 0),
            'discovered_at' => now()->subDays(3)->setTime(10, 0), // 2h
        ]);

        $this->assertSame(1.25, $this->service->summary()['nfl_detection_lag_median_hours']);
    }

    public function test_missing_discovered_at_yields_null_lags_not_negative(): void
    {
        $this->incident(['discovered_at' => null]);

        $summary = $this->service->summary();

        $this->assertNull($summary['fl_detection_lag_median_hours']);
        $this->assertNull($summary['fl_usable_timing_pct']);
    }

    public function test_rca_within_5_days_uses_audit_trail(): void
    {
        // OwenIt's observer doesn't attach in the shared phpunit process
        // (audit.console gates console boot) — attach it explicitly, the
        // established pattern (AiWorkflowViewTest).
        Incident::observe(new \OwenIt\Auditing\AuditableObserver);

        // Fresh incident, RCA present at creation → audit today, within 5d.
        $this->incident(['root_cause' => 'immediate rc', 'incident_date' => now()->subDays(2)->setTime(9, 15)]);
        // Old incident (legacy: created before RCA existed), RCA only filled
        // today → 30d late. createQuietly also keeps its creation out of the
        // audit trail — matching legacy rows, whose first root_cause audit
        // is the backfill edit itself.
        $late = $this->legacyRow(['incident_date' => now()->subDays(30)->setTime(9, 15)]);
        $late->update(['root_cause' => 'filled after 30 days']);

        $result = $this->service->rcaWithinFiveDays();

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['coverage']); // both have a root_cause-filling audit
        $this->assertSame(50.0, $result['pct']); // one filled within 5d, one after 30d
    }

    public function test_summary_is_cached_until_version_bump(): void
    {
        $this->incident(['root_cause' => 'row']);
        $first = $this->service->summary();
        // Same version → TTL window serves the cached array untouched.
        $this->assertSame($first, $this->service->summary());

        // A new row recomputes (metrics job bumps dashboard_cache_version),
        // so the summary must pick it up despite the unexpired TTL.
        $this->incident(['root_cause' => 'row 2', 'incident_date' => now()->subDays(10)->startOfDay()]);
        $this->assertNotSame($first, $this->service->summary());
    }
}
