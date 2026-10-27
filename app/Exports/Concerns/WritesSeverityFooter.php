<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Enums\FundStatus;
use App\Enums\Severity;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Owner rule 2026-10-27 (restyled + MTTR split same day): every
 * incident-listing tab ends with a styled footer block — a severity
 * mini-table (P1..X4, zeros shown, METRIC_ELIGIBLE order) and a stats
 * mini-table. Avg MTTR is SPLIT by the storage convention
 * (IncidentMetricsCalculator, owner 2026-10-13): positive mttr = minutes
 * (non-fund-loss), negative mttr = elapsed days (fund loss) — each bucket
 * averages in its own unit, never mixed. Fund counts describe the tab's
 * own rows, so tab-vs-widget differences read straight off the sheet.
 */
trait WritesSeverityFooter
{
    /**
     * Writes the 5-row block (severity header/counts, blank, stats
     * header/values) starting at $row.
     *
     * @param  Builder|Collection  $source  the tab's own filtered set —
     *                                      never a pre-grouped builder
     *                                      (Edit-Safety rule 6).
     */
    protected function writeSeverityFooter(Worksheet $sheet, int $row, $source): void
    {
        [$sevCounts, $fundCounts, $avgMttrMins, $avgMttrDays] = $source instanceof Collection
            ? $this->footerStatsFromCollection($source)
            : $this->footerStatsFromQuery($source);

        $sheet->fromArray(Severity::METRIC_ELIGIBLE, null, "A{$row}");
        $sheet->getStyle("A{$row}:H{$row}")->applyFromArray(self::FOOTER_HEADER_STYLE);

        $counts = array_map(fn (string $s) => $sevCounts[$s] ?? 0, Severity::METRIC_ELIGIBLE);
        // ponytail: strict=true is load-bearing — fromArray's default loose
        // compare treats 0 == null and silently drops zero-count cells.
        $sheet->fromArray($counts, null, 'A'.($row + 1), true);
        $sheet->getStyle('A'.($row + 1).':H'.($row + 1))->applyFromArray(self::FOOTER_VALUE_STYLE);

        $statsRow = $row + 3;
        // ponytail: unit-labeled on purpose — the days bucket holds every
        // day-granularity status (Confirmed loss, Potential recovery, Fully
        // recovered, Non Tech Loss), so a "Fund Loss" label here would
        // contradict the Confirmed-loss-only count two cells over.
        $sheet->fromArray(['Avg MTTR (mins)', 'Avg MTTR (days)', 'Fund Loss', 'Non Fund Loss'], null, "A{$statsRow}");
        $sheet->getStyle("A{$statsRow}:D{$statsRow}")->applyFromArray(self::FOOTER_HEADER_STYLE);

        // '-' when the bucket has no rows on this tab; BUG-021: avg() is a
        // decimal-string on MySQL — cast before rounding.
        $sheet->fromArray([
            $avgMttrMins === null ? '-' : round((float) $avgMttrMins, 2),
            $avgMttrDays === null ? '-' : round((float) $avgMttrDays, 2),
            $fundCounts[FundStatus::ConfirmedLoss->value] ?? 0,
            $fundCounts[FundStatus::NonFundLoss->value] ?? 0,
        ], null, 'A'.($statsRow + 1), true);
        $sheet->getStyle('A'.($statsRow + 1).':D'.($statsRow + 1))->applyFromArray(self::FOOTER_VALUE_STYLE);
    }

    /** Same visual language as the sheets' yellow summary headers. */
    private const FOOTER_HEADER_STYLE = [
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFEB9C']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ];

    private const FOOTER_VALUE_STYLE = [
        'font' => ['bold' => true],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ];

    /**
     * @return array{0: Collection<string,int>, 1: Collection<string,int>, 2: float|int|null, 3: float|int|null}
     */
    private function footerStatsFromQuery(Builder $query): array
    {
        // ponytail: reorder() is load-bearing — call sites hand us ordered
        // builders and MySQL ONLY_FULL_GROUP_BY (strict mode) rejects ORDER BY
        // on a non-grouped column (error 1055); SQLite tests can't see it.
        // MTTR split by storage sign (owner 2026-10-13 convention):
        // >= 0 = minutes bucket, < 0 = elapsed-days bucket (averaged abs).
        $daysAvg = $query->clone()->reorder()->where('mttr', '<', 0)->avg('mttr');

        return [
            $query->clone()->reorder()->selectRaw('severity, COUNT(*) as count')->groupBy('severity')->pluck('count', 'severity'),
            $query->clone()->reorder()->selectRaw('fund_status, COUNT(*) as count')->groupBy('fund_status')->pluck('count', 'fund_status'),
            $query->clone()->reorder()->where('mttr', '>=', 0)->avg('mttr'),
            $daysAvg === null ? null : abs((float) $daysAvg),
        ];
    }

    /**
     * @return array{0: Collection<string,int>, 1: Collection<string,int>, 2: float|int|null, 3: float|int|null}
     */
    private function footerStatsFromCollection(Collection $incidents): array
    {
        $value = fn ($v) => $v instanceof BackedEnum ? $v->value : $v;
        $byDays = $incidents->filter(fn ($i) => $i->mttr !== null && $i->mttr < 0);
        $inMinutes = $incidents->filter(fn ($i) => $i->mttr !== null && $i->mttr >= 0);

        return [
            $incidents->countBy(fn ($i) => $value($i->severity)),
            $incidents->countBy(fn ($i) => $value($i->fund_status)),
            $inMinutes->isEmpty() ? null : $inMinutes->avg('mttr'),
            $byDays->isEmpty() ? null : abs((float) $byDays->avg('mttr')),
        ];
    }
}
