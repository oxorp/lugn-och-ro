<?php

namespace App\Jobs;

use App\Models\Indicator;
use App\Models\PoiCategory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregatePoiCategoryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    private const BATCH_SIZE = 500;

    /** Transit categories are aggregated from transit_stops table instead of pois */
    private const TRANSIT_CATEGORY = 'public_transport_stop';

    public function __construct(
        public string $categorySlug,
        public int $year,
    ) {}

    public function handle(): void
    {
        $startTime = microtime(true);

        $category = PoiCategory::query()->where('slug', $this->categorySlug)->firstOrFail();
        $indicator = Indicator::query()->where('slug', $category->indicator_slug)->firstOrFail();

        $catchmentMeters = (float) $category->catchment_km * 1000;
        $now = now()->toDateTimeString();

        // Transit uses transit_stops table (GTFS authority) instead of pois
        $useTransitStops = $this->categorySlug === self::TRANSIT_CATEGORY
            && DB::table('transit_stops')->exists();

        Log::info("POI aggregation started: {$category->name}", [
            'category' => $this->categorySlug,
            'catchment_km' => $category->catchment_km,
            'year' => $this->year,
            'source_table' => $useTransitStops ? 'transit_stops' : 'pois',
        ]);

        $poiCount = $useTransitStops
            ? DB::table('transit_stops')->whereNotNull('geom')->count()
            : DB::table('pois')
                ->where('category', $this->categorySlug)
                ->where('status', 'active')
                ->whereNotNull('geom')
                ->count();

        // Get all DeSO codes with population
        $allDesos = DB::select('
            SELECT deso_code, population
            FROM deso_areas
            WHERE population IS NOT NULL AND population > 0
            ORDER BY deso_code
        ');

        $rows = [];
        $nonZero = 0;

        if ($poiCount >= 10000) {
            // Batched approach for large categories
            Log::info("Using batched aggregation for {$this->categorySlug} ({$poiCount} points, batches of ".self::BATCH_SIZE.')');

            foreach (array_chunk($allDesos, self::BATCH_SIZE) as $batchIndex => $batch) {
                $desoCodes = array_map(fn ($d) => $d->deso_code, $batch);
                $populationMap = [];
                foreach ($batch as $d) {
                    $populationMap[$d->deso_code] = $d->population;
                }

                $placeholders = implode(',', array_fill(0, count($desoCodes), '?'));

                if ($useTransitStops) {
                    $results = DB::select("
                        SELECT
                            d.deso_code,
                            COUNT(ts.id) AS poi_count
                        FROM deso_areas d
                        LEFT JOIN transit_stops ts ON
                            ts.geom IS NOT NULL
                            AND ST_DWithin(
                                ts.geom::geography,
                                d.geom::geography,
                                ?
                            )
                        WHERE d.deso_code IN ({$placeholders})
                        GROUP BY d.deso_code
                    ", array_merge([$catchmentMeters], $desoCodes));
                } else {
                    $results = DB::select("
                        SELECT
                            d.deso_code,
                            COUNT(p.id) AS poi_count
                        FROM deso_areas d
                        LEFT JOIN pois p ON
                            p.category = ?
                            AND p.status = 'active'
                            AND p.geom IS NOT NULL
                            AND ST_DWithin(
                                p.geom::geography,
                                d.geom::geography,
                                ?
                            )
                        WHERE d.deso_code IN ({$placeholders})
                        GROUP BY d.deso_code
                    ", array_merge([$this->categorySlug, $catchmentMeters], $desoCodes));
                }

                $resultMap = [];
                foreach ($results as $r) {
                    $resultMap[$r->deso_code] = $r->poi_count;
                }

                foreach ($desoCodes as $desoCode) {
                    $poiFound = $resultMap[$desoCode] ?? 0;
                    $population = $populationMap[$desoCode];
                    $rawValue = ($poiFound / $population) * 1000;

                    $rows[] = [
                        'deso_code' => $desoCode,
                        'indicator_id' => $indicator->id,
                        'year' => $this->year,
                        'raw_value' => round($rawValue, 4),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if ($poiFound > 0) {
                        $nonZero++;
                    }
                }

                // Flush rows to DB periodically
                if (count($rows) >= 1000) {
                    $this->flushRows($rows);
                    $rows = [];
                }
            }
        } else {
            // Single query for small categories
            if ($useTransitStops) {
                $results = DB::select('
                    SELECT
                        d.deso_code,
                        d.population,
                        COUNT(ts.id) AS poi_count
                    FROM deso_areas d
                    LEFT JOIN transit_stops ts ON
                        ts.geom IS NOT NULL
                        AND ST_DWithin(
                            ts.geom::geography,
                            d.geom::geography,
                            ?
                        )
                    WHERE d.population IS NOT NULL
                      AND d.population > 0
                    GROUP BY d.deso_code, d.population
                ', [$catchmentMeters]);
            } else {
                $results = DB::select("
                    SELECT
                        d.deso_code,
                        d.population,
                        COUNT(p.id) AS poi_count
                    FROM deso_areas d
                    LEFT JOIN pois p ON
                        p.category = ?
                        AND p.status = 'active'
                        AND p.geom IS NOT NULL
                        AND ST_DWithin(
                            p.geom::geography,
                            d.geom::geography,
                            ?
                        )
                    WHERE d.population IS NOT NULL
                      AND d.population > 0
                    GROUP BY d.deso_code, d.population
                ", [$this->categorySlug, $catchmentMeters]);
            }

            foreach ($results as $row) {
                $rawValue = ($row->poi_count / $row->population) * 1000;

                $rows[] = [
                    'deso_code' => $row->deso_code,
                    'indicator_id' => $indicator->id,
                    'year' => $this->year,
                    'raw_value' => round($rawValue, 4),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($row->poi_count > 0) {
                    $nonZero++;
                }
            }
        }

        // Store null for DeSOs with zero population
        $desosWithZeroPop = DB::select('
            SELECT deso_code
            FROM deso_areas
            WHERE (population IS NULL OR population = 0)
        ');

        foreach ($desosWithZeroPop as $row) {
            $rows[] = [
                'deso_code' => $row->deso_code,
                'indicator_id' => $indicator->id,
                'year' => $this->year,
                'raw_value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Flush remaining rows
        $this->flushRows($rows);

        $elapsed = round(microtime(true) - $startTime, 1);
        $totalDesos = count($allDesos) + count($desosWithZeroPop);

        Log::info("POI aggregation completed: {$category->name}", [
            'category' => $this->categorySlug,
            'desos' => $totalDesos,
            'non_zero' => $nonZero,
            'poi_count' => $poiCount,
            'source_table' => $useTransitStops ? 'transit_stops' : 'pois',
            'elapsed_seconds' => $elapsed,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flushRows(array &$rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('indicator_values')->upsert(
                $chunk,
                ['deso_code', 'indicator_id', 'year'],
                ['raw_value', 'updated_at']
            );
        }
    }
}
