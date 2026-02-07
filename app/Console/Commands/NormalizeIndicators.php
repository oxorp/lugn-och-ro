<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Services\NormalizationService;
use Illuminate\Console\Command;

class NormalizeIndicators extends Command
{
    use LogsIngestion;

    protected $signature = 'normalize:indicators
        {--year= : Year to normalize (defaults to latest year with data)}';

    protected $description = 'Compute normalized values (percentile rank) for all indicator values';

    public function handle(NormalizationService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $this->startIngestionLog('scoring', 'normalize:indicators');

        try {
            $this->info("Normalizing indicators for year {$year}...");

            $updated = $service->normalizeAll($year);
            $this->processed = $updated;
            $this->updated = $updated;
            $this->addStat('year', $year);

            $this->info("Normalized {$updated} indicator values.");
            $this->completeIngestionLog();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failIngestionLog($e->getMessage());
            $this->error("Normalization failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
