<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectScoresToH3 extends Command
{
    protected $signature = 'project:scores-to-h3
        {--year= : Year to project scores for (defaults to current year - 1)}
        {--resolution=8 : H3 resolution}';

    protected $description = 'Project DeSO composite scores onto H3 hexagonal cells';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->year - 1);
        $resolution = (int) $this->option('resolution');

        $this->info("Projecting scores to H3 (year={$year}, resolution={$resolution})...");

        // Verify we have composite scores for this year
        $scoreCount = DB::table('composite_scores')->where('year', $year)->count();
        if ($scoreCount === 0) {
            $this->error("No composite scores found for year {$year}. Run compute:scores first.");

            return self::FAILURE;
        }

        $this->info("Found {$scoreCount} DeSO scores.");

        // Clear existing H3 scores for this year/resolution
        DB::table('h3_scores')
            ->where('year', $year)
            ->where('resolution', $resolution)
            ->delete();

        // Project scores: each hex inherits the score from the DeSO with the largest area weight.
        // Multiple DeSOs can map to the same hex (small DeSOs assigned by centroid), so we
        // use DISTINCT ON to pick the one with the highest weight.
        $inserted = DB::affectingStatement('
            INSERT INTO h3_scores (h3_index, year, resolution, score_raw, factor_scores, trend_1y, primary_deso_code, computed_at, created_at, updated_at)
            SELECT DISTINCT ON (m.h3_index)
                m.h3_index,
                cs.year,
                m.resolution,
                cs.score,
                cs.factor_scores,
                cs.trend_1y,
                m.deso_code,
                NOW(),
                NOW(),
                NOW()
            FROM deso_h3_mapping m
            JOIN composite_scores cs ON cs.deso_code = m.deso_code AND cs.year = ?
            WHERE m.resolution = ?
            ORDER BY m.h3_index, m.area_weight DESC
        ', [$year, $resolution]);

        $this->info("Projected {$inserted} H3 scores.");

        // Summary stats
        $stats = DB::selectOne('
            SELECT
                COUNT(*) AS total,
                ROUND(AVG(score_raw)::numeric, 2) AS avg_score,
                ROUND(STDDEV(score_raw)::numeric, 2) AS stddev,
                ROUND(MIN(score_raw)::numeric, 2) AS min_score,
                ROUND(MAX(score_raw)::numeric, 2) AS max_score
            FROM h3_scores
            WHERE year = ? AND resolution = ?
        ', [$year, $resolution]);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total H3 cells', number_format($stats->total)],
                ['Avg score', $stats->avg_score],
                ['Stddev', $stats->stddev],
                ['Min', $stats->min_score],
                ['Max', $stats->max_score],
            ]
        );

        return self::SUCCESS;
    }
}
