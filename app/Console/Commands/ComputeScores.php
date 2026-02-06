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

        // Project to H3 and apply smoothing if the mapping table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('deso_h3_mapping')) {
            $mappingCount = \Illuminate\Support\Facades\DB::table('deso_h3_mapping')->count();
            if ($mappingCount > 0) {
                $this->call('project:scores-to-h3', ['--year' => $year]);
                $this->call('smooth:h3-scores', ['--year' => $year, '--config' => 'Light']);
            }
        }

        return self::SUCCESS;
    }
}
