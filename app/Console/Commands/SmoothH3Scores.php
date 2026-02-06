<?php

namespace App\Console\Commands;

use App\Models\SmoothingConfig;
use App\Services\SpatialSmoothingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SmoothH3Scores extends Command
{
    protected $signature = 'smooth:h3-scores
        {--year= : Year to smooth scores for (defaults to current year - 1)}
        {--config=Light : Smoothing config name (None, Light, Medium, Strong)}
        {--resolution=8 : H3 resolution}';

    protected $description = 'Apply spatial smoothing to H3 hexagonal scores';

    public function handle(SpatialSmoothingService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year - 1);
        $resolution = (int) $this->option('resolution');
        $configName = $this->option('config');

        $config = SmoothingConfig::query()->where('name', $configName)->first();
        if (! $config) {
            $this->error("Smoothing config '{$configName}' not found.");
            $this->info('Available configs: '.SmoothingConfig::pluck('name')->implode(', '));

            return self::FAILURE;
        }

        $this->info("Applying '{$config->name}' smoothing (self={$config->self_weight}, neighbor={$config->neighbor_weight}, k={$config->k_rings}, decay={$config->decay_function})...");

        $count = $service->smooth($year, $resolution, $config);

        $this->info("Smoothed {$count} H3 scores.");

        // Compare raw vs smoothed
        $stats = DB::selectOne('
            SELECT
                ROUND(STDDEV(score_raw)::numeric, 2) AS raw_stddev,
                ROUND(STDDEV(score_smoothed)::numeric, 2) AS smoothed_stddev,
                ROUND(AVG(ABS(score_raw - score_smoothed))::numeric, 2) AS avg_delta,
                ROUND(MAX(ABS(score_raw - score_smoothed))::numeric, 2) AS max_delta
            FROM h3_scores
            WHERE year = ? AND resolution = ? AND score_smoothed IS NOT NULL
        ', [$year, $resolution]);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Raw stddev', $stats->raw_stddev],
                ['Smoothed stddev', $stats->smoothed_stddev],
                ['Avg |delta|', $stats->avg_delta],
                ['Max |delta|', $stats->max_delta],
            ]
        );

        // Pre-aggregate lower resolutions for fast viewport queries
        $this->aggregateLowerResolutions($year, $resolution);

        return self::SUCCESS;
    }

    /**
     * Pre-compute aggregated scores at lower resolutions (5, 6, 7)
     * so viewport queries at any zoom level are a simple index lookup.
     */
    private function aggregateLowerResolutions(int $year, int $baseResolution): void
    {
        $lowerResolutions = [5, 6, 7];

        foreach ($lowerResolutions as $targetRes) {
            if ($targetRes >= $baseResolution) {
                continue;
            }

            // Delete existing lower-res scores for this year
            DB::table('h3_scores')
                ->where('year', $year)
                ->where('resolution', $targetRes)
                ->delete();

            $inserted = DB::affectingStatement('
                INSERT INTO h3_scores (h3_index, year, resolution, score_raw, score_smoothed, trend_1y, primary_deso_code, computed_at, created_at, updated_at)
                SELECT
                    parent_hex,
                    hs.year,
                    ?,
                    ROUND(AVG(hs.score_raw)::numeric, 2),
                    ROUND(AVG(hs.score_smoothed)::numeric, 2),
                    ROUND(AVG(hs.trend_1y)::numeric, 2),
                    NULL,
                    NOW(), NOW(), NOW()
                FROM (
                    SELECT *, h3_cell_to_parent(h3_index::h3index, ?)::text AS parent_hex
                    FROM h3_scores
                    WHERE year = ? AND resolution = ? AND score_smoothed IS NOT NULL
                ) hs
                GROUP BY parent_hex, hs.year
            ', [$targetRes, $targetRes, $year, $baseResolution]);

            $this->info("  Aggregated {$inserted} hexes at resolution {$targetRes}.");
        }
    }
}
