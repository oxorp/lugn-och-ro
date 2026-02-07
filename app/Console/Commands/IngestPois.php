<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Models\Poi;
use App\Models\PoiCategory;
use App\Services\OverpassService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestPois extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:pois
        {--source=osm : Data source (osm, google_places)}
        {--category= : Specific category slug, or omit for all active}
        {--all : Process all active categories with OSM tags}';

    protected $description = 'Ingest POI data from external sources (Overpass/OSM)';

    private const RATE_LIMIT_SECONDS = 10;

    public function handle(OverpassService $overpass): int
    {
        $source = $this->option('source');
        $categories = $this->getCategories();

        if ($categories->isEmpty()) {
            $this->error('No matching categories found.');

            return self::FAILURE;
        }

        $this->startIngestionLog('pois', 'ingest:pois');
        $this->addStat('source', $source);
        $this->addStat('categories', $categories->pluck('slug')->all());

        $totalCreated = 0;
        $totalUpdated = 0;

        foreach ($categories as $index => $category) {
            $this->info("Ingesting: {$category->name} from {$source}");

            try {
                if ($source === 'osm') {
                    if (empty($category->osm_tags)) {
                        $this->warn("  Skipping {$category->slug} — no OSM tags defined.");

                        continue;
                    }

                    $points = $overpass->querySweden($category->osm_tags);
                } elseif ($source === 'google_places') {
                    $this->warn('  Google Places adapter not yet implemented. Skipping.');

                    continue;
                } else {
                    $this->error("Unknown source: {$source}");

                    return self::FAILURE;
                }
            } catch (\RuntimeException $e) {
                $this->error("  Failed to fetch {$category->slug}: {$e->getMessage()}");
                $this->warn('  Continuing with next category...');

                if ($index < $categories->count() - 1) {
                    sleep(self::RATE_LIMIT_SECONDS);
                }

                continue;
            }

            $pointCount = $points->count();
            $this->info("  Found {$pointCount} points");

            $now = now();

            // Chunk-upsert to avoid memory exhaustion on large datasets
            foreach ($points->chunk(1000) as $chunk) {
                $rows = [];
                foreach ($chunk as $point) {
                    $rows[] = [
                        'external_id' => "osm_{$point['osm_type']}_{$point['osm_id']}",
                        'source' => $source,
                        'category' => $category->slug,
                        'poi_type' => $category->slug,
                        'display_tier' => $category->display_tier ?? 4,
                        'sentiment' => $category->signal ?? 'neutral',
                        'name' => mb_substr($point['name'] ?? '', 0, 255) ?: null,
                        'lat' => round($point['lat'], 7),
                        'lng' => round($point['lng'], 7),
                        'tags' => json_encode($point['tags'] ?? []),
                        'status' => 'active',
                        'last_verified_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('pois')->upsert(
                    $rows,
                    ['source', 'external_id'],
                    ['category', 'poi_type', 'display_tier', 'sentiment', 'name', 'lat', 'lng', 'tags', 'status', 'last_verified_at', 'updated_at']
                );
            }

            // Free the collection to reclaim memory
            unset($points);

            // Set PostGIS geometry for all POIs that don't have one or have moved
            $geomUpdated = DB::update('
                UPDATE pois
                SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
                WHERE category = ?
                  AND source = ?
                  AND (geom IS NULL OR ST_X(geom) != lng OR ST_Y(geom) != lat)
            ', [$category->slug, $source]);

            // Mark stale POIs from this source/category that weren't in this scrape
            $staleCount = Poi::query()
                ->where('source', $source)
                ->where('category', $category->slug)
                ->where('status', 'active')
                ->where('last_verified_at', '<', now()->subMonths(6))
                ->update(['status' => 'unverified']);

            $poiCount = Poi::query()
                ->where('source', $source)
                ->where('category', $category->slug)
                ->where('status', 'active')
                ->count();

            $this->info("  Active POIs: {$poiCount}, Geometry updated: {$geomUpdated}, Stale marked: {$staleCount}");

            $totalCreated += $pointCount;

            // Rate limiting between categories (except last)
            if ($index < $categories->count() - 1) {
                $this->info('  Waiting '.self::RATE_LIMIT_SECONDS.'s (rate limit)...');
                sleep(self::RATE_LIMIT_SECONDS);
            }
        }

        $this->processed = $totalCreated + $totalUpdated;
        $this->created = $totalCreated;
        $this->completeIngestionLog();

        $this->newLine();
        $this->info('POI ingestion complete. Run assign:poi-deso next.');

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PoiCategory>
     */
    private function getCategories(): \Illuminate\Database\Eloquent\Collection
    {
        $source = $this->option('source');

        $query = PoiCategory::query()->where('is_active', true);

        if ($this->option('category')) {
            $query->where('slug', $this->option('category'));
        } elseif ($source === 'osm') {
            $query->whereNotNull('osm_tags');
        }

        return $query->get();
    }
}
