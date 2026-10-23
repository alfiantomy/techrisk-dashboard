<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Full-calendar-year MTBF gap sequence (IncidentTableExport semantics —
 * Custom preset + Quarterly Report tabs). All-Tabs sheets sequence per-tab
 * instead (SingleIncidentSheetExport); do not merge the two.
 */
trait ComputesMtbfSequence
{
    /** Per-instance, NOT static — static froze the sequence across requests in long-lived FPM workers (prod bug 2026-09-30). */
    private array $mtbfSequenceCache = [];

    /**
     * When set, the sequence runs over this already-filtered builder instead
     * of the whole table, so export f_* filters (e.g. fund status) scope the
     * gaps — excluded rows telescope over (owner report 2026-10-23: exports
     * scoped to Confirmed loss + Non fundLoss must reproduce the owner's
     * hand-made sheet, not the unfiltered chain). Null = legacy global scope.
     */
    protected ?Builder $mtbfScope = null;

    /**
     * Gap sequence, METRIC_ELIGIBLE only. First of the year has no
     * predecessor → null (renders '-') so the column's average equals
     * span/(n-1), matching the bottom summary and the widgets (the old
     * Jan-1 dayOfYear anchor broke that equality — owner report 2026-09-30).
     */
    protected function mtbfSequenceValue($incident): ?int
    {
        $year = $incident->incident_date->year;
        $key = "export_all_{$year}";

        if (! isset($this->mtbfSequenceCache[$key])) {
            $incidents = ($this->mtbfScope ? $this->mtbfScope->clone() : \App\Models\Incident::query())
                ->whereYear('incident_date', $year)
                ->where('classification', '!=', IncidentClassification::Issue->value)
                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->withoutOutliers()
                ->orderBy('incident_date')->orderBy('id')
                ->get(['id', 'incident_date']);

            $this->mtbfSequenceCache[$key] = [];
            foreach ($incidents as $i => $inc) {
                $this->mtbfSequenceCache[$key][$inc->id] = $i === 0
                    ? null
                    : (int) $incidents[$i - 1]->incident_date->startOfDay()
                        ->diffInDays($inc->incident_date->startOfDay());
            }
        }

        return $this->mtbfSequenceCache[$key][$incident->id] ?? null;
    }
}
