<?php

namespace App\Console\Commands;

use App\Models\Indicator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckFreshness extends Command
{
    protected $signature = 'check:freshness';

    protected $description = 'Check data freshness for all indicators and update their status';

    /**
     * Freshness thresholds by source: [stale_months, outdated_months]
     *
     * @var array<string, array{int, int}>
     */
    private array $thresholds = [
        'scb' => [15, 24],
        'skolverket' => [15, 24],
        'bra' => [6, 12],
        'kronofogden' => [15, 24],
    ];

    public function handle(): int
    {
        $indicators = Indicator::query()->where('is_active', true)->get();
        $now = Carbon::now();

        $this->info("Checking freshness for {$indicators->count()} active indicators...");
        $this->newLine();

        $staleCount = 0;
        $outdatedCount = 0;

        foreach ($indicators as $indicator) {
            $lastIngested = $indicator->last_ingested_at;

            if (! $lastIngested) {
                // Infer from latest ingestion log
                $latestLog = \App\Models\IngestionLog::query()
                    ->where('source', $indicator->source)
                    ->where('status', 'completed')
                    ->latest('completed_at')
                    ->first();

                if ($latestLog) {
                    $lastIngested = $latestLog->completed_at;
                    $indicator->last_ingested_at = $lastIngested;
                }
            }

            if (! $lastIngested) {
                $indicator->update(['freshness_status' => 'unknown']);
                $this->warn("  ? {$indicator->slug}: Never ingested");

                continue;
            }

            $thresholds = $this->thresholds[$indicator->source] ?? [15, 24];
            $monthsSinceIngestion = $lastIngested->diffInMonths($now);

            $status = match (true) {
                $monthsSinceIngestion >= $thresholds[1] => 'outdated',
                $monthsSinceIngestion >= $thresholds[0] => 'stale',
                default => 'current',
            };

            $indicator->update(['freshness_status' => $status]);

            $icon = match ($status) {
                'current' => 'v',
                'stale' => '!',
                'outdated' => 'x',
                default => '?',
            };

            $msg = "  {$icon} {$indicator->slug}: {$status} (last ingested {$monthsSinceIngestion} months ago)";

            match ($status) {
                'outdated' => $this->error($msg) && $outdatedCount++,
                'stale' => $this->warn($msg) && $staleCount++,
                default => $this->info($msg),
            };
        }

        $this->newLine();

        if ($outdatedCount > 0) {
            $this->error("{$outdatedCount} indicator(s) are outdated.");
        }
        if ($staleCount > 0) {
            $this->warn("{$staleCount} indicator(s) are stale.");
        }
        if ($outdatedCount === 0 && $staleCount === 0) {
            $this->info('All indicators are current.');
        }

        return $outdatedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
