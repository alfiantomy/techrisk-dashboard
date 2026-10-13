<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\HasChartColors;
use App\Services\Governance\GovernanceKpiService;
use Filament\Widgets\ChartWidget;

/**
 * Median detection lag (hours) by quarter — the gap analysis's core numbers
 * (fund-loss detected in days, performance in minutes) as a live trend.
 */
class DetectionLagChart extends ChartWidget
{
    use HasChartColors;

    protected static ?string $heading = 'Detection Lag by Quarter (median hours)';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = app(GovernanceKpiService::class, [
            'startDate' => $this->start_date,
            'endDate' => $this->end_date,
        ])->lagByQuarter();

        return [
            'datasets' => [
                [
                    'label' => 'Fund Loss (Confirmed loss)',
                    'data' => $data['fl'],
                    'backgroundColor' => self::chartColors(1)[0],
                    'borderRadius' => 4,
                ],
                [
                    'label' => 'Non-Fund-Loss (Non fundLoss)',
                    'data' => $data['nfl'],
                    'backgroundColor' => self::chartColors(2)[1],
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $data['labels'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'hours'],
                ],
            ],
        ];
    }
}
