<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\FundStatus;
use App\Exports\IncidentTableExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-09-30): export MTBF gap sequences are computed over
 * METRIC_ELIGIBLE rows only — a G row between two eligible incidents
 * must not count as a gap event (surface drift found in review).
 *
 * Owner report (2026-10-23): when the export is scoped (f_fund_status =
 * Confirmed loss + Non fundLoss etc.), the sequence must run over the
 * filtered set — excluded rows telescope over, so the column and the
 * bottom Avg MTBF reproduce the owner's hand-made sheet, not the
 * unfiltered chain.
 */
class IncidentTableExportMtbfTest extends TestCase
{
    use RefreshDatabase;

    public function test_mtbf_sequence_ignores_non_metric_severity_rows(): void
    {
        // Year 2027: untouched by other tests (per-year static cache).
        $p1 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2027-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => '2027-01-20 10:00']);
        $p2 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2027-01-30 10:00']);

        $export = new IncidentTableExport(collect([$p1, $p2]), [], ['MTBF (days)'], ['mtbf']);

        // Without the filter the G row splits the gap: P2 would read 10
        // (gap from Jan 20). Eligible-only sequence: Jan 10 -> Jan 30 = 20.
        $this->assertSame(20, $export->map($p2)[0]);
    }

    public function test_mtbf_sequence_honors_fund_status_scope(): void
    {
        $a = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Confirmed loss', 'incident_date' => '2026-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'fund_status' => 'Fully recovered', 'incident_date' => '2026-01-20 10:00']);
        $c = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'fund_status' => 'Confirmed loss', 'incident_date' => '2026-01-30 10:00']);

        // No scope (legacy direct construction): global chain → C = 10.
        $global = new IncidentTableExport(collect([$a, $c]), [], ['MTBF (days)'], ['mtbf']);
        $this->assertSame(10, $global->map($c)[0]);

        // Scoped to the export's filtered query (owner's sheet recipe):
        // the Fully recovered row drops out of the chain → C telescopes to 20.
        $scope = Incident::query()->whereIn('fund_status', [
            FundStatus::ConfirmedLoss->value, FundStatus::NonFundLoss->value,
        ]);
        $scoped = new IncidentTableExport(collect([$a, $c]), [], ['MTBF (days)'], ['mtbf'], $scope);
        $this->assertSame(20, $scoped->map($c)[0]);
    }
}
