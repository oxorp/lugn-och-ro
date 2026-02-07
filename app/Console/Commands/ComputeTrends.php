<?php

namespace App\Console\Commands;

use App\Services\TrendService;
use Illuminate\Console\Command;

class ComputeTrends extends Command
{
    protected $signature = 'compute:trends
        {--base-year= : Start year for trend calculation (default: current year - 3)}
        {--end-year= : End year for trend calculation (default: current year - 1)}';

    protected $description = 'Compute per-indicator trends for trend-eligible DeSO areas';

    public function handle(TrendService $trendService): int
    {
        $baseYear = (int) ($this->option('base-year') ?? now()->year - 3);
        $endYear = (int) ($this->option('end-year') ?? now()->year - 1);

        $this->info("Computing trends from {$baseYear} to {$endYear}...");

        $stats = $trendService->computeTrends($baseYear, $endYear);

        $this->newLine();
        $this->info('Trend computation complete:');
        $this->info("  Indicators processed: {$stats['indicators_processed']}");
        $this->info("  Trends computed: {$stats['trends_computed']}");

        if ($stats['methodology_breaks'] > 0) {
            $this->warn("  Methodology breaks: {$stats['methodology_breaks']} (trends skipped)");
        }

        return self::SUCCESS;
    }
}
