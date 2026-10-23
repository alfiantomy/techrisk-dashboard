<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\MultiSheetIncidentsExport;
use App\Exports\QuarterlyReportExport;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule 2026-10-23: the export baseline set is widget-aligned —
 * Confirmed loss + Non fundLoss only, the same rows the dashboard MTBF
 * cards count. All Cases / Completed (All Tabs), the Quarterly Report's
 * All Cases + quarter tabs, Custom and Executive data run on it by
 * default; an explicit Fund Status filter overrides the default; the
 * dedicated fund-status tabs keep every fund status (nothing is lost).
 */
class ExportWidgetBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Incident $a;

    private Incident $b;

    private Incident $c;

    private Incident $open;

    protected function setUp(): void
    {
        parent::setUp();

        // 2033 chain: A Jan 10 Confirmed, B Jan 20 Fully recovered
        // (excluded from baseline), C Jan 30 Confirmed. $open is a
        // non-completed Fully recovered row for the tab checks.
        $make = fn (string $date, array $attrs = []) => Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech', 'incident_date' => "{$date} 10:00",
        ], $attrs));

        $this->a = $make('2033-01-10', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $this->b = $make('2033-01-20', ['severity' => 'P1', 'fund_status' => 'Fully recovered', 'recovered_fund' => 100]);
        $this->c = $make('2033-01-30', ['severity' => 'P2', 'fund_status' => 'Confirmed loss']);
        $this->open = $make('2033-01-25', ['severity' => 'P1', 'fund_status' => 'Fully recovered', 'incident_status' => 'Open']);
    }

    private function baseline(): \Illuminate\Database\Eloquent\Builder
    {
        $query = ExportActionSchema::applyFilters(Incident::query(), []);

        return ExportActionSchema::baselineQuery($query, []);
    }

    public function test_baseline_defaults_to_widget_fund_scope(): void
    {
        $this->assertSame(2, $this->baseline()->count());
        $this->assertTrue($this->baseline()->pluck('id')->contains($this->a->id));
        $this->assertTrue($this->baseline()->pluck('id')->contains($this->c->id));
    }

    public function test_explicit_fund_status_filter_overrides_the_default(): void
    {
        $query = ExportActionSchema::applyFilters(Incident::query(), ['f_fund_status' => ['Fully recovered']]);
        $baseline = ExportActionSchema::baselineQuery($query, ['f_fund_status' => ['Fully recovered']]);

        $ids = $baseline->pluck('id');
        $this->assertTrue($ids->contains($this->b->id));
        $this->assertTrue($ids->contains($this->open->id));
        $this->assertFalse($ids->contains($this->a->id));
    }

    public function test_baseline_keeps_other_export_filters(): void
    {
        $query = ExportActionSchema::applyFilters(Incident::query(), ['f_severity' => ['P2']]);
        $baseline = ExportActionSchema::baselineQuery($query, []);

        $this->assertSame(1, $baseline->count());
        $this->assertSame($this->c->id, $baseline->first()->id);
    }

    public function test_all_tabs_general_sheets_are_scoped_dedicated_keep_their_rows(): void
    {
        $sheets = (new MultiSheetIncidentsExport($this->baseline(), ['Title', 'MTBF (days)'], ['title', 'mtbf'], Incident::query()->whereIn('severity', ['P1', 'P2'])))->sheets();
        $counts = [];
        foreach ($sheets as $sheet) {
            if (! str_starts_with($sheet->title(), 'Issues') && $sheet->title() !== 'All Issues') {
                $counts[$sheet->title()] = $sheet->query()->count();
            }
        }

        // Baseline scope: A + C only; the Fully recovered rows live on in
        // their dedicated tabs and the cross-cutting sheets.
        $this->assertSame([
            'All Cases' => 2,
            'Completed Cases' => 2,
            'Recovered Cases' => 1,        // B (recovered_fund > 0)
            'P4 Incidents' => 0,
            'Non-Tech Incidents' => 0,
            'Fund Loss' => 2,              // A + C (Confirmed loss)
            'On Going' => 1,               // the open Fully recovered row
            'Potential Recovery' => 0,
            'Fully Recovered' => 2,        // B + open — never emptied by the baseline
            'Non Tech Loss' => 0,
            'Non Fund Loss' => 0,
        ], $counts);

        // The scoped All Cases sequence telescopes over B: C reads 20.
        $allCases = $sheets[0];
        $this->assertSame('-', $allCases->map($this->a)[1]);
        $this->assertSame(20, $allCases->map($this->c)[1]);
    }

    public function test_quarterly_report_all_cases_scoped_recovered_tab_keeps_rows(): void
    {
        $sheets = (new QuarterlyReportExport($this->baseline(), Incident::query()->whereIn('severity', ['P1', 'P2'])))->sheets();
        $counts = [];
        foreach ($sheets as $sheet) {
            $counts[$sheet->title()] = $sheet->query()->count();
        }

        $this->assertSame([
            'All Cases' => 2,          // baseline — the owner's sheet
            'Recovered Cases' => 1,    // B via the dedicated base
            'Fund Loss' => 2,
            'Non Fund Loss' => 0,
            'Q1 2033' => 2,            // quarter tabs are baseline breakdowns
        ], $counts);

        // Scoped sequence: C telescopes over the excluded B row.
        $this->assertSame(20, $sheets[0]->map($this->c)[2]);
    }
}
