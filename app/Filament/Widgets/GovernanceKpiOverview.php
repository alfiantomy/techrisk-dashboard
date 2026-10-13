<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Governance\GovernanceKpiService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Governance scorecard (VP TechOps PDR §10). RAG colors follow the PDR
 * thresholds; "Negative MTTR = 0" replaced by FL usable-timing % (negative
 * MTTR is the day-unit encoding, not a defect — findings.md 2026-10-13).
 */
class GovernanceKpiOverview extends BaseWidget
{
    protected ?string $heading = 'Governance Scorecard';

    protected int|string|array $columnSpan = 'full';

    // Scorecard is the page's primary content — no lazy skeleton.
    protected static bool $isLazy = false;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getStats(): array
    {
        $s = app(GovernanceKpiService::class, [
            'startDate' => $this->start_date,
            'endDate' => $this->end_date,
        ])->summary();

        $period = (string) $s['period_label'];

        return [
            Stat::make('Register Completeness', $s['register_completeness_pct'] === null ? '—' : $s['register_completeness_pct'].'%')
                ->description("Completed rows fully documented ({$period})")
                ->color($this->ragPct($s['register_completeness_pct'], 95, 100)),

            Stat::make('RCA Within 5 Days', $s['rca_within_5d']['pct'] === null ? '—' : $s['rca_within_5d']['pct'].'%')
                ->description("Audit-trail based · covers {$s['rca_within_5d']['coverage']}/{$s['rca_within_5d']['total']} completed-with-RCA rows")
                ->color($this->ragPct($s['rca_within_5d']['pct'], 80, 100)),

            Stat::make('Overdue Action Items', (string) $s['overdue_action_items'])
                ->description('Pending past due date (all, not just reminder-flagged)')
                ->color($this->ragZero($s['overdue_action_items'], 3)),

            Stat::make('Recurrence This Quarter', (string) $s['recurrence_this_quarter'])
                ->description('Incidents flagged recurring in the current quarter')
                ->color($this->ragZero($s['recurrence_this_quarter'], 1)),

            Stat::make('FL Detection Lag (median)', $this->hoursLabel($s['fl_detection_lag_median_hours']))
                ->description('Confirmed-loss stream · discovered − occurred')
                ->color($this->ragLag($s['fl_detection_lag_median_hours'])),

            Stat::make('FL Usable Timing', $s['fl_usable_timing_pct'] === null ? '—' : $s['fl_usable_timing_pct'].'%')
                // ponytail: share thresholds mirror the PDR's "automated detection share" bands
                ->color($this->ragPct($s['fl_usable_timing_pct'], 20, 50)),

            Stat::make('FL Unknown Channel', $s['fl_unknown_channel_pct'] === null ? '—' : $s['fl_unknown_channel_pct'].'%')
                ->description('Confirmed-loss rows with no/Unknown detection channel — PDR red > 5%')
                // Inverted bands: a LOW share is the good state.
                ->color(match (true) {
                    $s['fl_unknown_channel_pct'] === null => 'gray',
                    $s['fl_unknown_channel_pct'] <= 5 => 'success',
                    $s['fl_unknown_channel_pct'] <= 20 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Placeholder Timestamps', (string) $s['placeholder_time_count'])
                ->description("Occurrence times unknown — 00:00/12:00 or flagged ({$period})"),
        ];
    }

    /** PDR bands: green at/above $green, amber in between, red below $amber. */
    private function ragPct(?float $pct, float $amber, float $green): string
    {
        return match (true) {
            $pct === null => 'gray',
            $pct >= $green => 'success',
            $pct >= $amber => 'warning',
            default => 'danger',
        };
    }

    /** PDR bands for counts where 0 is the only green: 0 / 1..max / >max. */
    private function ragZero(int $count, int $max): string
    {
        return match (true) {
            $count === 0 => 'success',
            $count <= $max => 'warning',
            default => 'danger',
        };
    }

    /** PDR band: <1h green, 1–24h amber, >24h red. */
    private function ragLag(?float $hours): string
    {
        return match (true) {
            $hours === null => 'gray',
            $hours < 1 => 'success',
            $hours <= 24 => 'warning',
            default => 'danger',
        };
    }

    private function hoursLabel(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        return $hours < 24
            ? round($hours, 1).'h'
            : round($hours / 24, 1).'d';
    }
}
