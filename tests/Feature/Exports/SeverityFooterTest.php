<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\GroupedIncidentsExport;
use App\Exports\IncidentTableExport;
use App\Exports\MultiSheetIncidentsExport;
use App\Exports\QuarterlyReportExport;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Owner rule 2026-10-27: every incident-listing tab of every preset ends
 * with a styled footer block — a severity mini-table P1..X4 (zeros shown,
 * METRIC_ELIGIBLE order) and a stats mini-table whose Avg MTTR is SPLIT by
 * the storage convention (owner 2026-10-13): positive mttr = minutes
 * (non-fund-loss), negative mttr = elapsed days (fund loss, averaged abs)
 * — buckets never mix units. Fund counts describe the tab's OWN rows (a
 * null-fund row counts in severity but lands in no fund bucket).
 */
class SeverityFooterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Six rows, distinct severities, mixed fund statuses incl. a
        // null-fund one. Minutes-rows 60,60,60,60,30 + one days-row
        // (mttr -3, Confirmed loss — the elapsed-days convention); the
        // varied 30 makes the minutes average membership-sensitive.
        $make = fn (string $date, array $attrs = []) => Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech',
            'incident_date' => "{$date} 10:00", 'mttr' => 60, 'business_category' => ['Core Payment'],
        ], $attrs));

        $make('2033-01-10', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $make('2033-01-20', ['severity' => 'P2', 'fund_status' => 'Non fundLoss', 'mttr' => 30]); // varied so minutes-bucket membership is pinned
        $make('2033-01-25', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $make('2033-01-30', ['severity' => 'X2', 'fund_status' => null]);            // severity counts, no fund bucket
        $make('2033-02-10', ['severity' => 'P3', 'fund_status' => 'Fully recovered']); // excluded from the baseline
        $make('2033-02-20', ['severity' => 'P1', 'fund_status' => 'Confirmed loss', 'mttr' => -3]); // days bucket
    }

    private function baseline(): \Illuminate\Database\Eloquent\Builder
    {
        return ExportActionSchema::baselineQuery(
            ExportActionSchema::applyFilters(Incident::query(), []),
            [],
        );
    }

    /** @return Spreadsheet */
    private function render($export)
    {
        $fname = 'severity-footer-test-'.uniqid().'.xlsx';
        Excel::store($export, $fname, 'local');
        $spread = IOFactory::load(Storage::disk('local')->path($fname));
        Storage::disk('local')->delete($fname);

        return $spread;
    }

    public function test_quarterly_all_cases_footer_block_below_total_cases(): void
    {
        // Baseline = 5 rows (P3 Fully recovered excluded): header 1 + 5 data
        // → Total Cases at 8 → block: severity hdr 9, counts 10, stats hdr 12,
        // values 13.
        $data = $this->render(new QuarterlyReportExport($this->baseline(), Incident::query()))
            ->getSheetByName('All Cases');

        $this->assertSame('Total Cases', $data->getCell('A8')->getValue());
        $this->assertSame(
            ['P1', 'P2', 'P3', 'P4', 'X1', 'X2', 'X3', 'X4'],
            array_map(fn ($c) => $data->getCell($c)->getValue(), ['A9', 'B9', 'C9', 'D9', 'E9', 'F9', 'G9', 'H9']),
        );
        $this->assertSame(
            [3, 1, 0, 0, 0, 1, 0, 0],
            array_map(fn ($c) => $data->getCell($c)->getValue(), ['A10', 'B10', 'C10', 'D10', 'E10', 'F10', 'G10', 'H10']),
        );

        $this->assertSame('Avg MTTR (mins)', $data->getCell('A12')->getValue());
        $this->assertSame('Avg MTTR (days)', $data->getCell('B12')->getValue());
        $this->assertSame('Fund Loss', $data->getCell('C12')->getValue());
        $this->assertSame('Non Fund Loss', $data->getCell('D12')->getValue());
        // Minutes bucket = (60+30+60+60)/4 = 52.5 — the varied 30 pins
        // membership; days bucket = |−3| = 3 must NOT pollute it (would be
        // 45). Fund 3 / Non-Fund 1.
        $this->assertEqualsWithDelta(52.5, (float) $data->getCell('A13')->getValue(), 0.001);
        $this->assertEqualsWithDelta(3, (float) $data->getCell('B13')->getValue(), 0.001);
        $this->assertSame(3, $data->getCell('C13')->getValue());
        $this->assertSame(1, $data->getCell('D13')->getValue());
    }

    public function test_quarterly_fund_loss_tab_footer_matches_its_own_rows(): void
    {
        // Fund Loss tab = the 3 Confirmed loss rows: footer counts equal the
        // tab's own membership, not the workbook's.
        $data = $this->render(new QuarterlyReportExport($this->baseline(), Incident::query()))
            ->getSheetByName('Fund Loss');

        // 3 data rows → Total Cases at 6 → severity hdr 7, counts 8, values 11.
        $this->assertSame(3, $data->getCell('A8')->getValue());
        $this->assertSame(0, $data->getCell('B8')->getValue());
        $this->assertEqualsWithDelta(60, (float) $data->getCell('A11')->getValue(), 0.001); // (60+60)/2 — P2's 30 is not on this tab
        $this->assertEqualsWithDelta(3, (float) $data->getCell('B11')->getValue(), 0.001);
        $this->assertSame(3, $data->getCell('C11')->getValue());
        $this->assertSame(0, $data->getCell('D11')->getValue());
    }

    public function test_all_tabs_all_cases_footer_below_summary_block(): void
    {
        // Summary block: label at lastDataRow+2, header +3, values +4 →
        // footer block at +6 (severity hdr 12, counts 13, stats values 16).
        $export = new MultiSheetIncidentsExport($this->baseline(), ['Title', 'MTBF (days)'], ['title', 'mtbf'],
            Incident::query()->whereIn('severity', ['P1', 'P2', 'P3', 'X2']));
        $data = $this->render($export)->getSheetByName('All Cases');

        $this->assertSame('P1', $data->getCell('A12')->getValue());
        $this->assertSame(3, $data->getCell('A13')->getValue());
        $this->assertSame(1, $data->getCell('F13')->getValue(), 'X2 count at column F');
        $this->assertEqualsWithDelta(52.5, (float) $data->getCell('A16')->getValue(), 0.001);
        $this->assertEqualsWithDelta(3, (float) $data->getCell('B16')->getValue(), 0.001);
    }

    public function test_group_by_category_sheet_has_footer(): void
    {
        // PerCategorySheet had no footer at all before — its first AfterSheet.
        $export = new GroupedIncidentsExport(
            ExportActionSchema::applyFilters(Incident::query(), []),
            'business_category',
        );
        $data = $this->render($export)->getSheetByName('Core Payment');

        // 6 rows share Core Payment (JSON membership — the excluded P3 too):
        // header 1 + 6 → severity hdr 9, counts 10, stats values 13.
        $this->assertSame('P1', $data->getCell('A9')->getValue());
        $this->assertSame(3, $data->getCell('A10')->getValue());
        $this->assertSame(1, $data->getCell('C10')->getValue(), 'P3 rides along on the category sheet');
        $this->assertEqualsWithDelta(54, (float) $data->getCell('A13')->getValue(), 0.001); // (60+30+60+60+60)/5 — P3's 60 rides along
        $this->assertEqualsWithDelta(3, (float) $data->getCell('B13')->getValue(), 0.001);
    }

    public function test_custom_preset_footer_counts_from_collection(): void
    {
        // FromCollection path: counts derive from the loaded collection —
        // the sign split must match the SQL split.
        $incidents = $this->baseline()->orderBy('incident_date')->get();
        $stats = ['totalCases' => 5, 'avgMttr' => 60, 'totalPotentialFundLoss' => 0, 'totalFundLoss' => 0, 'totalRecoveredFund' => 0];
        $export = new IncidentTableExport($incidents, $stats, ['Title', 'MTBF (days)'], ['title', 'mtbf']);
        $data = $this->render($export)->getSheet(0);

        // 5 data rows: summary values at 10 → severity hdr 12, counts 13, stats values 16.
        $this->assertSame(3, $data->getCell('A13')->getValue());
        $this->assertEqualsWithDelta(52.5, (float) $data->getCell('A16')->getValue(), 0.001);
        $this->assertEqualsWithDelta(3, (float) $data->getCell('B16')->getValue(), 0.001);
        $this->assertSame(3, $data->getCell('C16')->getValue());
        $this->assertSame(1, $data->getCell('D16')->getValue());
    }
}
