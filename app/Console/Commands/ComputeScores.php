<?php

namespace App\Console\Commands;

use App\Services\ScoringService;
use Illuminate\Console\Command;

class ComputeScores extends Command
{
    protected $signature = 'compute:scores
        {--year= : Year to compute scores for (defaults to current year)}';

    protected $description = 'Compute composite neighborhood scores from normalized indicator values';

    public function handle(ScoringService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        $this->info("Computing composite scores for year {$year}...");

        $count = $service->computeScores($year);

        $this->info("Computed scores for {$count} DeSO areas.");

        return self::SUCCESS;
    }
}
