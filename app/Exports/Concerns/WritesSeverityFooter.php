<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Enums\FundStatus;
use App\Enums\Severity;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Owner rule 2026-10-27: every incident-listing tab ends with two bold
 * rows — per-severity counts (P1..X4, METRIC_ELIGIBLE order, zeros shown)
 * and Avg MTTR + Fund Loss / Non Fund Loss counts. The counts describe the
 * tab's OWN rows, so a tab-vs-widget count difference reads straight off
 * the sheet instead of needing a reconciliation.
 */
trait WritesSeverityFooter
{
    /**
     * @param  Builder|Collection  $source  the tab's own filtered set —
     *                                      never a pre-grouped builder
     *                                      (Edit-Safety rule 6).
     */
    protected function writeSeverityFooter(Worksheet $sheet, int $row, $source): void
    {
        [$sevCounts, $fundCounts, $avgMttr] = $source instanceof Collection
            ? $this->footerStatsFromCollection($source)
            : $this->footerStatsFromQuery($source);

        $sheet->fromArray(array_map(
            fn (string $s) => $s.': '.($sevCounts[$s] ?? 0),
            Severity::METRIC_ELIGIBLE,
        ), null, "A{$row}");
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);

        $next = $row + 1;
        // BUG-021: avg() returns a decimal-string on MySQL — cast before rounding.
        $sheet->fromArray([
            'Avg MTTR: '.($avgMttr === null ? '-' : round((float) $avgMttr, 2)),
            'Fund Loss: '.($fundCounts[FundStatus::ConfirmedLoss->value] ?? 0),
            'Non Fund Loss: '.($fundCounts[FundStatus::NonFundLoss->value] ?? 0),
        ], null, "A{$next}");
        $sheet->getStyle("A{$next}:C{$next}")->getFont()->setBold(true);
    }

    /** @return array{0: Collection<string,int>, 1: Collection<string,int>, 2: float|int|null} */
    private function footerStatsFromQuery(Builder $query): array
    {
        // ponytail: reorder() is load-bearing — call sites hand us ordered
        // builders and MySQL ONLY_FULL_GROUP_BY (strict mode) rejects ORDER BY
        // on a non-grouped column (error 3065); SQLite tests can't see it.
        return [
            $query->clone()->reorder()->selectRaw('severity, COUNT(*) as count')->groupBy('severity')->pluck('count', 'severity'),
            $query->clone()->reorder()->selectRaw('fund_status, COUNT(*) as count')->groupBy('fund_status')->pluck('count', 'fund_status'),
            // Same avg semantics as SingleIncidentSheetExport: metric-eligible,
            // positive (minutes) MTTR only — '-' when no such row exists.
            $query->clone()->whereIn('severity', Severity::METRIC_ELIGIBLE)->where('mttr', '>=', 0)->avg('mttr'),
        ];
    }

    /** @return array{0: Collection<string,int>, 1: Collection<string,int>, 2: float|int|null} */
    private function footerStatsFromCollection(Collection $incidents): array
    {
        $value = fn ($v) => $v instanceof BackedEnum ? $v->value : $v;

        return [
            $incidents->countBy(fn ($i) => $value($i->severity)),
            $incidents->countBy(fn ($i) => $value($i->fund_status)),
            $incidents
                ->filter(fn ($i) => in_array($value($i->severity), Severity::METRIC_ELIGIBLE, true)
                    && $i->mttr !== null && $i->mttr >= 0)
                ->avg('mttr'),
        ];
    }
}
