<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Models\PdfJob;
use Filament\Widgets\ChartWidget;

class PipelineStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Pipeline Status Breakdown';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getData(): array
    {
        $statuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];
        $labels = ['Pending', 'Processing', 'Completed', 'Failed', 'Cancelled'];

        $jobCounts = PdfJob::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $paymentCounts = Payment::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $jobsDataset = [];
        $paymentsDataset = [];

        foreach ($statuses as $status) {
            $jobsDataset[] = (int) ($jobCounts[$status] ?? 0);
            $paymentsDataset[] = (int) ($paymentCounts[$status] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'PDF Jobs',
                    'data' => $jobsDataset,
                    'backgroundColor' => 'rgba(245, 158, 11, 0.7)',
                    'borderRadius' => 8,
                ],
                [
                    'label' => 'Payments',
                    'data' => $paymentsDataset,
                    'backgroundColor' => 'rgba(14, 165, 233, 0.7)',
                    'borderRadius' => 8,
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
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}
