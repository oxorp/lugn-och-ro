<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Services\TrendService;
use Illuminate\Console\Command;

class ComputeTrends extends Command
{
    use LogsIngestion;

    protected $signature = 'compute:trends
        {--base-year= : Start year for trend calculation (default: current year - 3)}
        {--end-year= : End year for trend calculation (default: current year - 1)}';

    protected $description = 'Compute per-indicator trends for trend-eligible DeSO areas';

    public function handle(TrendService $trendService): int
    {
        $baseYear = (int) ($this->option('base-year') ?? now()->year - 3);
        $endYear = (int) ($this->option('end-year') ?? now()->year - 1);
        $this->startIngestionLog('scoring', 'compute:trends');

        try {
            $this->info("Computing trends from {$baseYear} to {$endYear}...");

            $stats = $trendService->computeTrends($baseYear, $endYear);
            $this->processed = $stats['trends_computed'];
            $this->addStat('indicators_processed', $stats['indicators_processed']);
            $this->addStat('trends_computed', $stats['trends_computed']);
            $this->addStat('methodology_breaks', $stats['methodology_breaks']);

            $this->newLine();
            $this->info('Trend computation complete:');
            $this->info("  Indicators processed: {$stats['indicators_processed']}");
            $this->info("  Trends computed: {$stats['trends_computed']}");

            if ($stats['methodology_breaks'] > 0) {
                $this->addWarning("Methodology breaks: {$stats['methodology_breaks']} (trends skipped)");
            }

            $this->completeIngestionLog();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failIngestionLog($e->getMessage());
            $this->error("Trend computation failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
