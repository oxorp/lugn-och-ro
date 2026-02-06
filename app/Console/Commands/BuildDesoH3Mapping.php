<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildDesoH3Mapping extends Command
{
    protected $signature = 'build:deso-h3-mapping
        {--resolution=8 : H3 resolution (default 8, ~0.74 km² per hex)}';

    protected $description = 'Build the DeSO-to-H3 hexagonal grid mapping table';

    public function handle(): int
    {
        $resolution = (int) $this->option('resolution');

        $this->info("Building DeSO → H3 mapping at resolution {$resolution}...");

        $totalDeso = DB::table('deso_areas')->count();
        $this->info("Processing {$totalDeso} DeSO areas...");

        // Truncate existing mapping for this resolution
        DB::table('deso_h3_mapping')->where('resolution', $resolution)->delete();

        // Process DeSOs in batches to avoid memory issues
        $batchSize = 100;
        $processed = 0;
        $totalHexes = 0;

        $bar = $this->output->createProgressBar($totalDeso);
        $bar->start();

        DB::table('deso_areas')
            ->select('deso_code')
            ->orderBy('deso_code')
            ->chunk($batchSize, function ($desoBatch) use ($resolution, &$processed, &$totalHexes, $bar) {
                $desoCodes = $desoBatch->pluck('deso_code')->toArray();
                $placeholders = implode(',', array_fill(0, count($desoCodes), '?'));

                // Use h3_polygon_to_cells with centroid approach (Approach A)
                // Each hex centroid falls in exactly one DeSO → weight is always 1.0
                DB::statement("
                    INSERT INTO deso_h3_mapping (deso_code, h3_index, area_weight, resolution, created_at, updated_at)
                    SELECT
                        d.deso_code,
                        h3_polygon_to_cells(d.geom, {$resolution})::text AS h3_index,
                        1.0 AS area_weight,
                        {$resolution} AS resolution,
                        NOW(),
                        NOW()
                    FROM deso_areas d
                    WHERE d.deso_code IN ({$placeholders})
                    ON CONFLICT (deso_code, h3_index) DO NOTHING
                ", $desoCodes);

                $processed += count($desoCodes);
                $bar->advance(count($desoCodes));
            });

        $bar->finish();
        $this->newLine();

        // Handle small DeSOs that didn't get any hex (smaller than a single hex cell)
        // Assign them to the H3 cell containing their centroid
        $unmapped = DB::select('
            SELECT da.deso_code
            FROM deso_areas da
            LEFT JOIN deso_h3_mapping m ON m.deso_code = da.deso_code AND m.resolution = ?
            WHERE m.id IS NULL
        ', [$resolution]);

        if (count($unmapped) > 0) {
            $this->info('Assigning '.count($unmapped).' small DeSOs to nearest H3 cell...');

            DB::statement("
                INSERT INTO deso_h3_mapping (deso_code, h3_index, area_weight, resolution, created_at, updated_at)
                SELECT
                    da.deso_code,
                    h3_latlng_to_cell(ST_Centroid(da.geom)::point, {$resolution})::text AS h3_index,
                    1.0 AS area_weight,
                    {$resolution} AS resolution,
                    NOW(),
                    NOW()
                FROM deso_areas da
                LEFT JOIN deso_h3_mapping m ON m.deso_code = da.deso_code AND m.resolution = {$resolution}
                WHERE m.id IS NULL
                ON CONFLICT (deso_code, h3_index) DO NOTHING
            ");

            $this->info('Small DeSOs assigned.');
        }

        // Report results
        $totalHexes = DB::table('deso_h3_mapping')->where('resolution', $resolution)->count();
        $desoCount = DB::table('deso_h3_mapping')
            ->where('resolution', $resolution)
            ->distinct('deso_code')
            ->count('deso_code');

        $this->info('Mapping complete:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total hexagons', number_format($totalHexes)],
                ['DeSO areas mapped', number_format($desoCount)." / {$totalDeso}"],
                ['Avg hexes per DeSO', $desoCount > 0 ? number_format($totalHexes / $desoCount, 1) : 'N/A'],
                ['Resolution', $resolution],
            ]
        );

        // Distribution of hexes per DeSO
        $distribution = DB::select("
            SELECT
                CASE
                    WHEN cnt <= 5 THEN '1-5'
                    WHEN cnt <= 20 THEN '6-20'
                    WHEN cnt <= 100 THEN '21-100'
                    WHEN cnt <= 500 THEN '101-500'
                    ELSE '500+'
                END AS hex_range,
                COUNT(*) AS deso_count
            FROM (
                SELECT deso_code, COUNT(*) AS cnt
                FROM deso_h3_mapping
                WHERE resolution = ?
                GROUP BY deso_code
            ) sub
            GROUP BY 1 ORDER BY 1
        ", [$resolution]);

        $this->info('Hexes per DeSO distribution:');
        $this->table(
            ['Hex range', 'DeSO count'],
            array_map(fn ($r) => [$r->hex_range, $r->deso_count], $distribution)
        );

        return self::SUCCESS;
    }
}
