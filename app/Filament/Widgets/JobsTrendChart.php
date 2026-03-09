<?php

namespace App\Filament\Widgets;

use App\Models\PdfJob;
use Filament\Widgets\ChartWidget;

class JobsTrendChart extends ChartWidget
{
    protected static ?string $heading = 'PDF Jobs Trend (Last 14 Days)';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getData(): array
    {
        $start = now()->subDays(13)->startOfDay();
        $end = now()->endOfDay();

        $createdByDate = PdfJob::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $completedByDate = PdfJob::query()
            ->selectRaw('DATE(updated_at) as date, COUNT(*) as total')
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $labels = [];
        $created = [];
        $completed = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('M j');
            $created[] = (int) ($createdByDate[$key] ?? 0);
            $completed[] = (int) ($completedByDate[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Created',
                    'data' => $created,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'pointRadius' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Completed',
                    'data' => $completed,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'pointRadius' => 2,
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
