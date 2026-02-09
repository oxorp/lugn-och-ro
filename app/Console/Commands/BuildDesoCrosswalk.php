<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildDesoCrosswalk extends Command
{
    protected $signature = 'build:deso-crosswalk
        {--fresh : Truncate the crosswalk table before building}
        {--min-overlap=0.01 : Minimum overlap fraction to include (default 1%)}';

    protected $description = 'Compute the DeSO 2018→2025 crosswalk using PostGIS spatial overlap';

    public function handle(): int
    {
        $this->info('Building DeSO 2018→2025 crosswalk...');

        $oldCount = DB::table('deso_areas_2018')->count();
        $newCount = DB::table('deso_areas')->count();

        if ($oldCount === 0) {
            $this->error('No DeSO 2018 areas found. Run import:deso-2018-boundaries first.');

            return self::FAILURE;
        }

        if ($newCount === 0) {
            $this->error('No DeSO 2025 areas found. Run import:deso-areas first.');

            return self::FAILURE;
        }

        $this->info("DeSO 2018 areas: {$oldCount}");
        $this->info("DeSO 2025 areas: {$newCount}");

        if ($this->option('fresh')) {
            $this->warn('Truncating deso_crosswalk table...');
            DB::table('deso_crosswalk')->truncate();
        }

        $minOverlap = (float) $this->option('min-overlap');
        $this->info("Minimum overlap threshold: {$minOverlap}");

        $this->info('Fixing invalid geometries in-place...');
        $fixedOld = DB::affectingStatement('UPDATE deso_areas_2018 SET geom = ST_MakeValid(geom) WHERE NOT ST_IsValid(geom)');
        $fixedNew = DB::affectingStatement('UPDATE deso_areas SET geom = ST_MakeValid(geom) WHERE NOT ST_IsValid(geom)');
        $this->info("  Fixed {$fixedOld} old geometries, {$fixedNew} new geometries.");

        $this->info('Computing spatial overlaps (this may take several minutes)...');

        $now = now()->toDateTimeString();

        $inserted = DB::affectingStatement("
            INSERT INTO deso_crosswalk (old_code, new_code, overlap_fraction, reverse_fraction, mapping_type, created_at, updated_at)
            SELECT
                old.deso_code as old_code,
                new.deso_code as new_code,
                ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) as overlap_fraction,
                ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(new.geom), 0) as reverse_fraction,
                CASE
                    WHEN ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) > 0.95
                     AND ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(new.geom), 0) > 0.95
                    THEN '1:1'
                    WHEN ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) < 0.95
                    THEN 'split'
                    WHEN ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(new.geom), 0) < 0.95
                    THEN 'merge'
                    ELSE 'partial'
                END as mapping_type,
                :now1 as created_at,
                :now2 as updated_at
            FROM deso_areas_2018 old
            JOIN deso_areas new ON ST_Intersects(old.geom, new.geom)
            WHERE ST_Area(ST_Intersection(old.geom, new.geom)) / NULLIF(ST_Area(old.geom), 0) > :min_overlap
            ON CONFLICT (old_code, new_code) DO UPDATE SET
                overlap_fraction = EXCLUDED.overlap_fraction,
                reverse_fraction = EXCLUDED.reverse_fraction,
                mapping_type = EXCLUDED.mapping_type,
                updated_at = EXCLUDED.updated_at
        ", [
            'now1' => $now,
            'now2' => $now,
            'min_overlap' => $minOverlap,
        ]);

        $this->info("Inserted/updated {$inserted} crosswalk mappings.");

        $this->verifyResults();

        return self::SUCCESS;
    }

    private function verifyResults(): void
    {
        $this->newLine();
        $this->info('=== Crosswalk Verification ===');

        // Mapping type distribution
        $typeCounts = DB::table('deso_crosswalk')
            ->selectRaw('mapping_type, COUNT(*) as count')
            ->groupBy('mapping_type')
            ->pluck('count', 'mapping_type');

        $this->info('Mapping type distribution:');
        foreach ($typeCounts as $type => $count) {
            $this->info("  {$type}: {$count}");
        }

        // Coverage
        $distinctOld = DB::table('deso_crosswalk')->distinct()->count('old_code');
        $distinctNew = DB::table('deso_crosswalk')->distinct()->count('new_code');
        $this->info("Distinct old codes mapped: {$distinctOld}");
        $this->info("Distinct new codes mapped: {$distinctNew}");

        // Check overlap fraction sums
        $badSums = DB::table('deso_crosswalk')
            ->selectRaw('old_code, SUM(overlap_fraction) as total')
            ->groupBy('old_code')
            ->havingRaw('ABS(SUM(overlap_fraction) - 1.0) > 0.05')
            ->count();

        if ($badSums > 0) {
            $this->warn("WARNING: {$badSums} old codes have overlap fractions not summing to ~1.0");
        } else {
            $this->info('All old codes have overlap fractions summing to ~1.0');
        }

        // Show a few split examples
        $splits = DB::table('deso_crosswalk')
            ->where('mapping_type', 'split')
            ->limit(5)
            ->get();

        if ($splits->isNotEmpty()) {
            $this->newLine();
            $this->info('Sample split mappings:');
            foreach ($splits as $row) {
                $this->info("  {$row->old_code} → {$row->new_code} (overlap: ".round($row->overlap_fraction * 100, 1).'%)');
            }
        }
    }
}
