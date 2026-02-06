<?php

namespace App\Console\Commands;

use App\Models\CompositeScore;
use App\Models\SentinelArea;
use Illuminate\Console\Command;

class CheckSentinels extends Command
{
    protected $signature = 'check:sentinels
        {--year= : Year to check (defaults to previous year)}';

    protected $description = 'Verify sentinel area scores are within expected ranges';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->year - 1);
        $sentinels = SentinelArea::query()->where('is_active', true)->get();

        if ($sentinels->isEmpty()) {
            $this->warn('No active sentinel areas configured.');

            return self::SUCCESS;
        }

        $this->info("Checking {$sentinels->count()} sentinel areas for year {$year}...");
        $this->newLine();

        $failures = 0;
        $results = [];

        foreach ($sentinels as $sentinel) {
            $score = CompositeScore::query()
                ->where('deso_code', $sentinel->deso_code)
                ->where('year', $year)
                ->value('score');

            if ($score === null) {
                $this->warn("  ? {$sentinel->name} ({$sentinel->deso_code}): No score computed");
                $failures++;
                $results[] = [
                    'name' => $sentinel->name,
                    'deso_code' => $sentinel->deso_code,
                    'status' => 'missing',
                    'score' => null,
                    'expected_min' => (float) $sentinel->expected_score_min,
                    'expected_max' => (float) $sentinel->expected_score_max,
                ];

                continue;
            }

            $score = (float) $score;
            $inRange = $score >= (float) $sentinel->expected_score_min
                && $score <= (float) $sentinel->expected_score_max;

            if (! $inRange) {
                $this->error("  x {$sentinel->name}: Score {$score} outside expected range [{$sentinel->expected_score_min}-{$sentinel->expected_score_max}]");
                $failures++;
            } else {
                $this->info("  v {$sentinel->name}: Score {$score} (expected {$sentinel->expected_score_min}-{$sentinel->expected_score_max})");
            }

            $results[] = [
                'name' => $sentinel->name,
                'deso_code' => $sentinel->deso_code,
                'status' => $inRange ? 'passed' : 'failed',
                'score' => $score,
                'expected_min' => (float) $sentinel->expected_score_min,
                'expected_max' => (float) $sentinel->expected_score_max,
            ];
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} sentinel check(s) failed. Review before publishing scores.");

            return self::FAILURE;
        }

        $this->info('All sentinel checks passed.');

        return self::SUCCESS;
    }
}
