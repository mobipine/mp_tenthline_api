<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Models\PdfJob;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $todayStart = now()->startOfDay();
        $yesterdayStart = now()->subDay()->startOfDay();
        $weekStart = now()->startOfWeek();

        $totalRevenue = (float) Payment::query()
            ->where('status', 'completed')
            ->sum('amount');

        $todayRevenue = (float) Payment::query()
            ->where('status', 'completed')
            ->where('created_at', '>=', $todayStart)
            ->sum('amount');

        $yesterdayRevenue = (float) Payment::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$yesterdayStart, $todayStart])
            ->sum('amount');

        $paymentsCount = Payment::query()->count();
        $completedPaymentsCount = Payment::query()
            ->where('status', 'completed')
            ->count();

        $paymentSuccessRate = $paymentsCount > 0
            ? round(($completedPaymentsCount / $paymentsCount) * 100, 1)
            : 0.0;

        $completedJobsCount = PdfJob::query()
            ->where('status', 'completed')
            ->count();

        $completedJobsThisWeek = PdfJob::query()
            ->where('status', 'completed')
            ->where('updated_at', '>=', $weekStart)
            ->count();

        $activeJobsCount = PdfJob::query()
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $customersCount = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'customer'))
            ->count();

        $todayVsYesterday = $yesterdayRevenue > 0
            ? round((($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100, 1)
            : ($todayRevenue > 0 ? 100.0 : 0.0);

        $trendLabel = $todayVsYesterday >= 0
            ? '+' . number_format($todayVsYesterday, 1) . '% vs yesterday'
            : number_format($todayVsYesterday, 1) . '% vs yesterday';

        return [
            Stat::make('Total Revenue', $this->formatKes($totalRevenue))
                ->description('Today: ' . $this->formatKes($todayRevenue))
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart($this->dailyRevenueForLastWeek())
                ->color('success'),

            Stat::make('Payment Success Rate', number_format($paymentSuccessRate, 1) . '%')
                ->description("{$completedPaymentsCount} of {$paymentsCount} payments completed")
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($paymentSuccessRate >= 70 ? 'success' : 'warning'),

            Stat::make('Completed Jobs', number_format($completedJobsCount))
                ->description("{$completedJobsThisWeek} completed this week")
                ->descriptionIcon('heroicon-m-document-check')
                ->chart($this->dailyCompletedJobsForLastWeek())
                ->color('primary'),

            Stat::make('Active Jobs', number_format($activeJobsCount))
                ->description('Pending + processing right now')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($activeJobsCount > 0 ? 'warning' : 'gray'),

            Stat::make('Customers', number_format($customersCount))
                ->description($trendLabel)
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }

    private function dailyRevenueForLastWeek(): array
    {
        $start = now()->subDays(6)->startOfDay();
        $end = now()->endOfDay();

        $totals = Payment::query()
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $series = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $series[] = (float) ($totals[$date->toDateString()] ?? 0);
        }

        return $series;
    }

    private function dailyCompletedJobsForLastWeek(): array
    {
        $start = now()->subDays(6)->startOfDay();
        $end = now()->endOfDay();

        $totals = PdfJob::query()
            ->selectRaw('DATE(updated_at) as date, COUNT(*) as total')
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $series = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $series[] = (int) ($totals[$date->toDateString()] ?? 0);
        }

        return $series;
    }

    private function formatKes(float $amount): string
    {
        return 'KES ' . number_format($amount, 2);
    }
}
