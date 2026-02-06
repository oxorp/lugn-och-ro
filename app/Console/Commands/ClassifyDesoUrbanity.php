<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClassifyDesoUrbanity extends Command
{
    protected $signature = 'classify:deso-urbanity
        {--method=density : Classification method (density, tatort, scb)}';

    protected $description = 'Classify DeSO areas by urbanity tier (urban / semi_urban / rural)';

    public function handle(): int
    {
        $method = $this->option('method');

        return match ($method) {
            'density' => $this->classifyByDensity(),
            'tatort' => $this->classifyByTatort(),
            'scb' => $this->classifyByScb(),
            default => $this->invalidMethod($method),
        };
    }

    private function classifyByDensity(): int
    {
        $this->info('Classifying DeSOs by population density...');

        $totalDesos = DB::table('deso_areas')->count();
        if ($totalDesos === 0) {
            $this->error('No DeSO areas found in database.');

            return self::FAILURE;
        }

        // Population may be stored directly on deso_areas or as an indicator value.
        // Backfill from indicator_values if deso_areas.population is null.
        $backfilled = $this->backfillPopulation();
        if ($backfilled > 0) {
            $this->info("Backfilled population for {$backfilled} DeSOs from indicator values.");
        }

        // Calculate density distribution to find natural breakpoints
        $densities = DB::table('deso_areas')
            ->whereNotNull('population')
            ->whereNotNull('area_km2')
            ->where('area_km2', '>', 0)
            ->selectRaw('population / area_km2 as density')
            ->orderBy('density')
            ->pluck('density')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        if (! empty($densities)) {
            $this->info(sprintf('Density range: %.1f to %.1f people/km²', min($densities), max($densities)));
        }

        // Thresholds: urban > 1500, semi_urban > 100, rural <= 100
        $urbanThreshold = 1500;
        $semiUrbanThreshold = 100;

        DB::update("
            UPDATE deso_areas
            SET urbanity_tier = CASE
                WHEN population / NULLIF(area_km2, 0) > ? THEN 'urban'
                WHEN population / NULLIF(area_km2, 0) > ? THEN 'semi_urban'
                ELSE 'rural'
            END,
            updated_at = NOW()
            WHERE population IS NOT NULL AND area_km2 IS NOT NULL AND area_km2 > 0
        ", [$urbanThreshold, $semiUrbanThreshold]);

        // Handle DeSOs with missing data — set to rural as default
        $nullCount = DB::table('deso_areas')
            ->whereNull('urbanity_tier')
            ->count();

        if ($nullCount > 0) {
            DB::table('deso_areas')
                ->whereNull('urbanity_tier')
                ->update(['urbanity_tier' => 'rural', 'updated_at' => now()]);
            $this->warn("{$nullCount} DeSOs with missing population/area data defaulted to rural.");
        }

        $this->logDistribution();

        return self::SUCCESS;
    }

    /**
     * Backfill deso_areas.population from the 'population' indicator if available.
     */
    private function backfillPopulation(): int
    {
        $populationIndicatorId = DB::table('indicators')
            ->where('slug', 'population')
            ->value('id');

        if (! $populationIndicatorId) {
            return 0;
        }

        // Get the latest year with population data
        $latestYear = DB::table('indicator_values')
            ->where('indicator_id', $populationIndicatorId)
            ->whereNotNull('raw_value')
            ->max('year');

        if (! $latestYear) {
            return 0;
        }

        return DB::update('
            UPDATE deso_areas da
            SET population = iv.raw_value::integer,
                updated_at = NOW()
            FROM indicator_values iv
            WHERE iv.deso_code = da.deso_code
              AND iv.indicator_id = ?
              AND iv.year = ?
              AND iv.raw_value IS NOT NULL
              AND da.population IS NULL
        ', [$populationIndicatorId, $latestYear]);
    }

    private function classifyByTatort(): int
    {
        $this->error('Tätort boundary classification is not yet implemented.');
        $this->info('Use --method=density for now. Tätort intersection will be available after tätort boundary data is imported.');

        return self::FAILURE;
    }

    private function classifyByScb(): int
    {
        $this->error('SCB metadata classification is not yet implemented.');
        $this->info('Use --method=density for now.');

        return self::FAILURE;
    }

    private function invalidMethod(string $method): int
    {
        $this->error("Unknown classification method: {$method}");
        $this->info('Available methods: density, tatort, scb');

        return self::FAILURE;
    }

    private function logDistribution(): void
    {
        $distribution = DB::table('deso_areas')
            ->selectRaw('urbanity_tier, COUNT(*) as count')
            ->groupBy('urbanity_tier')
            ->orderByRaw("CASE urbanity_tier WHEN 'urban' THEN 1 WHEN 'semi_urban' THEN 2 WHEN 'rural' THEN 3 ELSE 4 END")
            ->get();

        $total = $distribution->sum('count');

        $this->newLine();
        $this->info('Urbanity Classification Distribution:');
        $this->table(
            ['Tier', 'Count', 'Percentage'],
            $distribution->map(fn ($row) => [
                $row->urbanity_tier ?? 'unclassified',
                number_format($row->count),
                sprintf('%.1f%%', ($row->count / $total) * 100),
            ])->toArray()
        );

        $unclassified = DB::table('deso_areas')->whereNull('urbanity_tier')->count();
        if ($unclassified > 0) {
            $this->warn("Unclassified: {$unclassified}");
        }
    }
}
