<?php

namespace Tests\Feature;

use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PROJ-011 Wave 2: detected_via / failed_control / detection_delay_days /
 * start_time_known — casts, the timing helpers they feed, the export
 * columns, and the cache-version bump on change.
 */
class DetectionFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function incident(array $attrs = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_date' => now()->subDays(5)->setTime(9, 0),
            'discovered_at' => now()->subDays(5)->setTime(11, 0),
        ], $attrs));
    }

    public function test_detection_enums_cast_both_ways(): void
    {
        $incident = $this->incident([
            'detected_via' => 'Reconciliation',
            'failed_control' => 'Prevention',
        ]);

        $fresh = $incident->fresh();

        $this->assertSame(\App\Enums\DetectedVia::Reconciliation, $fresh->detected_via);
        $this->assertSame(\App\Enums\FailedControl::Prevention, $fresh->failed_control);
    }

    public function test_start_time_known_defaults_to_the_placeholder_convention(): void
    {
        $exact = $this->incident(); // 09:00 — a real time
        $placeholder = $this->incident(['incident_date' => now()->subDays(5)->startOfDay()]); // 00:00
        $flagged = $this->incident([
            'incident_date' => now()->subDays(5)->startOfDay(),
            'start_time_known' => true,
        ]);
        $denied = $this->incident(['start_time_known' => false]); // explicit: even 09:00 is unknown

        $this->assertTrue($exact->startTimeKnown());
        $this->assertFalse($placeholder->startTimeKnown());
        $this->assertTrue($flagged->startTimeKnown());
        $this->assertFalse($denied->startTimeKnown());
    }

    public function test_effective_detection_lag_prefers_real_timestamps(): void
    {
        $incident = $this->incident(); // discovered +2h after occurrence

        $this->assertSame(2.0, $incident->effectiveDetectionLagHours());
    }

    public function test_effective_detection_lag_falls_back_to_recorded_delay(): void
    {
        $incident = $this->incident([
            'incident_date' => now()->subDays(5)->startOfDay(), // placeholder start
            'detection_delay_days' => 3,
        ]);

        $this->assertSame(72.0, $incident->effectiveDetectionLagHours());
    }

    public function test_effective_detection_lag_is_null_when_nothing_usable(): void
    {
        $incident = $this->incident(['discovered_at' => null]);

        $this->assertNull($incident->effectiveDetectionLagHours());
    }

    public function test_effective_detection_lag_never_returns_negative(): void
    {
        // discovered BEFORE occurrence (clock skew / entry mistake)
        $incident = $this->incident([
            'incident_date' => now()->subDays(5)->setTime(9, 0),
            'discovered_at' => now()->subDays(5)->setTime(8, 0),
        ]);

        $this->assertNull($incident->effectiveDetectionLagHours());
    }

    public function test_export_column_options_include_the_detection_fields(): void
    {
        $options = ExportActionSchema::columnOptions();

        $this->assertArrayHasKey('detected_via', $options);
        $this->assertArrayHasKey('failed_control', $options);
    }

    public function test_changing_detected_via_bumps_the_dashboard_cache_version(): void
    {
        $incident = $this->incident();
        $before = (int) Cache::get('dashboard_cache_version', 0);

        $incident->update(['detected_via' => 'Monitoring']);

        $this->assertGreaterThan($before, (int) Cache::get('dashboard_cache_version', 0));
    }
}
