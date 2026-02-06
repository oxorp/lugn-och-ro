<?php

namespace App\Console\Commands;

use App\Models\ScoreVersion;
use Illuminate\Console\Command;

class RollbackScores extends Command
{
    protected $signature = 'scores:rollback
        {--to-version= : Score version ID to rollback to}
        {--reason= : Reason for rollback}';

    protected $description = 'Rollback to a previous score version';

    public function handle(): int
    {
        $targetId = $this->option('to-version');
        if (! $targetId) {
            $this->error('--to-version is required.');

            return self::FAILURE;
        }

        $target = ScoreVersion::query()->find($targetId);
        if (! $target) {
            $this->error("Score version #{$targetId} not found.");

            return self::FAILURE;
        }

        $reason = $this->option('reason') ?? 'Manual rollback';

        // Mark the currently published version as rolled_back
        $current = ScoreVersion::query()
            ->where('year', $target->year)
            ->where('status', 'published')
            ->first();

        if ($current) {
            $current->update([
                'status' => 'rolled_back',
                'notes' => trim(($current->notes ?? '')."\nRolled back: {$reason}"),
            ]);
            $this->info("Rolled back version #{$current->id}.");
        }

        // Re-publish the target version
        $target->update([
            'status' => 'published',
            'published_at' => now(),
            'notes' => trim(($target->notes ?? '')."\nRestored via rollback: {$reason}"),
        ]);

        $this->info("Restored version #{$target->id} for year {$target->year}.");

        return self::SUCCESS;
    }
}
