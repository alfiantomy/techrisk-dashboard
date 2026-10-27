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
 * with two bold footer rows — per-severity counts P1..X4 (zeros shown,
 * METRIC_ELIGIBLE order) and Avg MTTR + Fund Loss / Non Fund Loss counts
 * of THAT tab's own rows (so tab-vs-widget count differences read off the
 * sheet; a null-fund row counts in severity but lands in no fund bucket).
 */
class SeverityFooterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Six rows, distinct severities, mixed fund statuses incl. a
        // null-fund one. Completed + positive MTTR so Avg MTTR is numeric.
        $make = fn (string $date, array $attrs = []) => Incident::factory()->createQuietly(array_merge([
            'classification' => 'Incident', 'incident_status' => 'Completed', 'incident_type' => 'Tech',
            'incident_date' => "{$date} 10:00", 'mttr' => 60, 'business_category' => ['Core Payment'],
        ], $attrs));

        $make('2033-01-10', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $make('2033-01-20', ['severity' => 'P2', 'fund_status' => 'Non fundLoss']);
        $make('2033-01-25', ['severity' => 'P1', 'fund_status' => 'Confirmed loss']);
        $make('2033-01-30', ['severity' => 'X2', 'fund_status' => null]);            // severity counts, no fund bucket
        $make('2033-02-10', ['severity' => 'P3', 'fund_status' => 'Fully recovered']); // excluded from the baseline
        $make('2033-02-20', ['severity' => 'P1', 'fund_status' => 'Confirmed loss', 'mttr' => 120]);
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

    public function test_quarterly_all_cases_footer_rows_below_total_cases(): void
    {
        // Baseline = 5 rows (P3 Fully recovered excluded): header 1 + 5 data
        // → lastDataRow 6 → Total Cases at 8 → severity row 9, MTTR row 10.
        $data = $this->render(new QuarterlyReportExport($this->baseline(), Incident::query()))
            ->getSheetByName('All Cases');

        $this->assertSame('Total Cases', $data->getCell('A8')->getValue());
        $this->assertSame(
            ['P1: 3', 'P2: 1', 'P3: 0', 'P4: 0', 'X1: 0', 'X2: 1', 'X3: 0', 'X4: 0'],
            array_map(fn ($c) => $data->getCell($c)->getValue(), ['A9', 'B9', 'C9', 'D9', 'E9', 'F9', 'G9', 'H9']),
        );
        // (60+60+60+60+120) / 5 = 72; Fund Loss 3 / Non Fund Loss 1 (null row in neither).
        $this->assertSame('Avg MTTR: 72', $data->getCell('A10')->getValue());
        $this->assertSame('Fund Loss: 3', $data->getCell('B10')->getValue());
        $this->assertSame('Non Fund Loss: 1', $data->getCell('C10')->getValue());
    }

    public function test_quarterly_fund_loss_tab_footer_matches_its_own_rows(): void
    {
        // Fund Loss tab = the 3 Confirmed loss rows: footer counts equal the
        // tab's own membership, not the workbook's.
        $data = $this->render(new QuarterlyReportExport($this->baseline(), Incident::query()))
            ->getSheetByName('Fund Loss');

        // 3 data rows → Total Cases at 6 → severity 7, MTTR 8.
        $this->assertSame('P1: 3', $data->getCell('A7')->getValue());
        $this->assertSame('Fund Loss: 3', $data->getCell('B8')->getValue());
        $this->assertSame('Non Fund Loss: 0', $data->getCell('C8')->getValue());
    }

    public function test_all_tabs_all_cases_footer_below_summary_block(): void
    {
        // Summary block: label at lastDataRow+2, header +3, values +4 → footer +6/+7.
        $export = new MultiSheetIncidentsExport($this->baseline(), ['Title', 'MTBF (days)'], ['title', 'mtbf'],
            Incident::query()->whereIn('severity', ['P1', 'P2', 'P3', 'X2']));
        $data = $this->render($export)->getSheetByName('All Cases');

        // 5 baseline rows: lastDataRow 6 → severity at 12, MTTR at 13.
        $this->assertSame('P1: 3', $data->getCell('A12')->getValue());
        $this->assertSame('X2: 1', $data->getCell('F12')->getValue());
        $this->assertSame('Avg MTTR: 72', $data->getCell('A13')->getValue());
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
        // header 1 + 6 → lastDataRow 7 → severity at 9, MTTR at 10.
        $this->assertSame('P1: 3', $data->getCell('A9')->getValue());
        $this->assertSame('P3: 1', $data->getCell('C9')->getValue());
        $this->assertSame('Avg MTTR: 70', $data->getCell('A10')->getValue());
    }

    public function test_custom_preset_footer_counts_from_collection(): void
    {
        // FromCollection path: counts derive from the loaded collection.
        $incidents = $this->baseline()->orderBy('incident_date')->get();
        $export = $stats = ['totalCases' => 5, 'avgMttr' => 72, 'totalPotentialFundLoss' => 0, 'totalFundLoss' => 0, 'totalRecoveredFund' => 0];
        $export = new IncidentTableExport($incidents, $stats, ['Title', 'MTTR (days)'], ['title', 'mtbf']);
        $data = $this->render($export)->getSheet(0);

        // 5 data rows: lastDataRow 6 → summary label 8, header 9, values 10 → footer 12/13.
        $this->assertSame('P1: 3', $data->getCell('A12')->getValue());
        $this->assertSame('Fund Loss: 3', $data->getCell('B13')->getValue());
        $this->assertSame('Avg MTTR: 72', $data->getCell('A13')->getValue());
    }
}
