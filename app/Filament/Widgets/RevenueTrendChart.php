<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\ChartWidget;

class RevenueTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Revenue Trend (Last 14 Days)';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getData(): array
    {
        $start = now()->subDays(13)->startOfDay();
        $end = now()->endOfDay();

        $revenueByDate = Payment::query()
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $countByDate = Payment::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $labels = [];
        $revenue = [];
        $counts = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('M j');
            $revenue[] = (float) ($revenueByDate[$key] ?? 0);
            $counts[] = (int) ($countByDate[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (KES)',
                    'data' => $revenue,
                    'backgroundColor' => 'rgba(245, 158, 11, 0.6)',
                    'borderColor' => '#f59e0b',
                    'borderWidth' => 1,
                    'yAxisID' => 'y',
                ],
                [
                    'label' => 'Completed Payments',
                    'data' => $counts,
                    'type' => 'line',
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.15)',
                    'pointRadius' => 2,
                    'tension' => 0.3,
                    'yAxisID' => 'y1',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'type' => 'linear',
                    'position' => 'left',
                    'title' => [
                        'display' => true,
                        'text' => 'KES',
                    ],
                ],
                'y1' => [
                    'type' => 'linear',
                    'position' => 'right',
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                    'title' => [
                        'display' => true,
                        'text' => 'Payments',
                    ],
                ],
            ],
        ];
    }
}
