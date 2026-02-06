<?php

namespace App\Console\Commands;

use App\Services\NormalizationService;
use Illuminate\Console\Command;

class NormalizeIndicators extends Command
{
    protected $signature = 'normalize:indicators
        {--year= : Year to normalize (defaults to latest year with data)}';

    protected $description = 'Compute normalized values (percentile rank) for all indicator values';

    public function handle(NormalizationService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        $this->info("Normalizing indicators for year {$year}...");

        $updated = $service->normalizeAll($year);

        $this->info("Normalized {$updated} indicator values.");

        return self::SUCCESS;
    }
}
