<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use ZipArchive;

class IngestGtfs extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:gtfs
        {--target-date= : Target weekday date for frequency computation (YYYYMMDD)}
        {--skip-download : Skip download, use existing extracted GTFS files}
        {--file= : Path to local GTFS zip file (skips download)}';

    protected $description = 'Ingest GTFS Sverige 2 transit data — replaces OSM transit with official timetable data';

    /** Display tier thresholds */
    private const TIER_1_MIN_DEPARTURES = 20;  // Major rail: >20 dep/day

    private const TIER_4_MIN_DEPARTURES = 56;  // High-freq bus: >=56 dep/day (4/hour × 14 hours)

    public function handle(): int
    {
        $this->startIngestionLog('gtfs', 'ingest:gtfs');

        try {
            $extractDir = $this->resolveGtfsFiles();
            if (! $extractDir) {
                $this->failIngestionLog('Could not obtain GTFS files');

                return self::FAILURE;
            }

            // Verify essential files exist
            foreach (['stops.txt', 'stop_times.txt', 'trips.txt', 'routes.txt', 'calendar.txt'] as $file) {
                if (! file_exists($extractDir.'/'.$file)) {
                    $this->error("Missing required file: {$file}");
                    $this->failIngestionLog("Missing {$file}");

                    return self::FAILURE;
                }
            }

            // Step 1: Clear old transit data
            $this->clearOldTransitData();

            // Step 2: Import stops (PHP — simple CSV, ~47k rows)
            $stopCount = $this->importStops($extractDir);

            // Step 3: Compute frequencies (Python — heavy lifting)
            $freqCsv = $this->computeFrequencies($extractDir);
            if (! $freqCsv) {
                $this->failIngestionLog('Frequency computation failed');

                return self::FAILURE;
            }

            // Step 4: Import frequencies (PHP — bulk insert from CSV)
            $freqCount = $this->importFrequencies($freqCsv);

            // Step 5: Backfill stop_type and weekly_departures on transit_stops
            $this->backfillStopMetrics();

            // Step 6: Spatial join (PostGIS — assign DeSO codes)
            $this->assignDesoCodes();

            // Step 7: Insert qualifying stops as POIs
            $poiCount = $this->insertTransitPois();

            $this->processed = $stopCount;
            $this->created = $stopCount;
            $this->addStat('stops_imported', $stopCount);
            $this->addStat('frequency_records', $freqCount);
            $this->addStat('pois_created', $poiCount);
            $this->completeIngestionLog();

            $this->newLine();
            $this->info('GTFS ingestion complete.');
            $this->info("  Stops: {$stopCount}");
            $this->info("  Frequency records: {$freqCount}");
            $this->info("  POIs created: {$poiCount}");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("GTFS ingestion failed: {$e->getMessage()}");
            $this->failIngestionLog($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveGtfsFiles(): ?string
    {
        $extractDir = storage_path('app/data/raw/gtfs');

        if ($this->option('skip-download') && is_dir($extractDir)) {
            $this->info('Using existing GTFS files (--skip-download).');

            return $extractDir;
        }

        $zipPath = $this->option('file');
        if ($zipPath && file_exists($zipPath)) {
            $this->info("Using local file: {$zipPath}");

            return $this->extractZip($zipPath, $extractDir);
        }

        // Download from Trafiklab
        $apiKey = config('services.trafiklab.gtfs_key');
        if (! $apiKey) {
            $this->error('No GTFS API key configured. Set TRAFIKLAB_GTFS_KEY in .env');
            $this->error('Get a key at https://www.trafiklab.se/api/gtfs-sverige-2/');

            return null;
        }

        $url = "https://opendata.samtrafiken.se/gtfs-sweden/sweden.zip?key={$apiKey}";
        $downloadPath = storage_path('app/data/raw/gtfs_sweden.zip');

        $this->info('Downloading GTFS Sverige 2...');

        // Ensure directory exists
        if (! is_dir(dirname($downloadPath))) {
            mkdir(dirname($downloadPath), 0755, true);
        }

        $response = Http::timeout(120)->withOptions(['sink' => $downloadPath])->get($url);

        if (! $response->successful()) {
            $this->error("Download failed: HTTP {$response->status()}");

            return null;
        }

        $size = round(filesize($downloadPath) / 1024 / 1024, 1);
        $this->info("  Downloaded {$size} MB");

        return $this->extractZip($downloadPath, $extractDir);
    }

    private function extractZip(string $zipPath, string $extractDir): ?string
    {
        $this->info('Extracting GTFS zip...');

        if (! is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Failed to open zip file');

            return null;
        }

        $zip->extractTo($extractDir);
        $zip->close();

        $this->info("  Extracted to {$extractDir}");

        return $extractDir;
    }

    private function clearOldTransitData(): void
    {
        $this->info('Clearing old transit data...');

        // Remove OSM-sourced transit POIs
        $deletedOsmPois = DB::table('pois')
            ->where('source', 'osm')
            ->where(function ($q) {
                $q->where('category', 'public_transport_stop')
                    ->orWhere('category', 'like', 'transit%')
                    ->orWhere('category', 'like', 'bus_stop%')
                    ->orWhere('category', 'like', 'tram_stop%')
                    ->orWhere('category', 'like', 'rail_station%')
                    ->orWhere('category', 'like', 'subway_station%');
            })
            ->delete();

        // Remove old GTFS data (for re-import idempotency)
        $deletedStops = DB::table('transit_stops')->where('source', 'gtfs')->delete();
        $deletedFreqs = DB::table('transit_stop_frequencies')->truncate();

        // Remove old GTFS POIs
        $deletedGtfsPois = DB::table('pois')->where('source', 'gtfs')->delete();

        $this->info("  Cleared: {$deletedOsmPois} OSM transit POIs, {$deletedStops} GTFS stops, {$deletedGtfsPois} GTFS POIs");
    }

    private function importStops(string $extractDir): int
    {
        $this->info('Importing stops...');

        $stopsFile = $extractDir.'/stops.txt';
        $handle = fopen($stopsFile, 'r');
        $header = fgetcsv($handle);
        $colMap = array_flip($header);

        $batch = [];
        $count = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            $locationType = isset($colMap['location_type']) ? (int) ($row[$colMap['location_type']] ?? 0) : 0;

            // Only import stops (0) and stations (1)
            if ($locationType > 1) {
                continue;
            }

            $lat = (float) $row[$colMap['stop_lat']];
            $lng = (float) $row[$colMap['stop_lon']];

            // Skip if outside Sweden bounds
            if ($lat < 55 || $lat > 69 || $lng < 11 || $lng > 25) {
                continue;
            }

            $parentStation = isset($colMap['parent_station']) ? ($row[$colMap['parent_station']] ?? null) : null;
            if ($parentStation === '') {
                $parentStation = null;
            }

            $batch[] = [
                'gtfs_stop_id' => $row[$colMap['stop_id']],
                'name' => mb_substr($row[$colMap['stop_name']] ?? '', 0, 255) ?: null,
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
                'parent_station' => $parentStation,
                'location_type' => $locationType,
                'source' => 'gtfs',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 1000) {
                DB::table('transit_stops')->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            DB::table('transit_stops')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);

        // Set PostGIS geometry column
        DB::statement("
            UPDATE transit_stops
            SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE source = 'gtfs' AND geom IS NULL
        ");

        $this->info("  Imported {$count} stops");

        return $count;
    }

    private function computeFrequencies(string $extractDir): ?string
    {
        $outputDir = storage_path('app/data/processed');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputCsv = $outputDir.'/gtfs_frequencies.csv';
        $targetDate = $this->option('target-date');

        $cmd = [
            '/opt/venv/bin/python3',
            base_path('scripts/compute_gtfs_frequencies.py'),
            $extractDir,
            $outputCsv,
        ];
        if ($targetDate) {
            $cmd[] = $targetDate;
        }

        $this->info('Computing frequencies via Python...');
        $result = Process::timeout(300)->run($cmd);

        if (! $result->successful()) {
            $this->error('Python frequency computation failed:');
            $this->error($result->errorOutput());

            return null;
        }

        $this->info($result->output());

        if (! file_exists($outputCsv)) {
            $this->error('Frequency CSV not created');

            return null;
        }

        return $outputCsv;
    }

    private function importFrequencies(string $csvPath): int
    {
        $this->info('Importing frequency data...');

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        $colMap = array_flip($header);

        $batch = [];
        $count = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            $batch[] = [
                'gtfs_stop_id' => $row[$colMap['stop_id']],
                'mode_category' => $row[$colMap['mode']],
                'departures_06_09' => (int) $row[$colMap['departures_06_09']],
                'departures_09_15' => (int) $row[$colMap['departures_09_15']],
                'departures_15_18' => (int) $row[$colMap['departures_15_18']],
                'departures_18_22' => (int) $row[$colMap['departures_18_22']],
                'departures_06_20_total' => (int) $row[$colMap['departures_06_20_total']],
                'distinct_routes' => (int) ($row[$colMap['distinct_routes']] ?? 0),
                'day_type' => $row[$colMap['day_type']] ?? 'weekday',
                'feed_version' => $row[$colMap['feed_version']] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 1000) {
                DB::table('transit_stop_frequencies')->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            DB::table('transit_stop_frequencies')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);

        $this->info("  Imported {$count} frequency records");

        return $count;
    }

    private function backfillStopMetrics(): void
    {
        $this->info('Backfilling stop_type and weekly_departures...');

        // Update transit_stops with frequency summaries for proximity scoring
        // Use the dominant mode (highest departure count) for stops served by multiple modes
        $updated = DB::update("
            UPDATE transit_stops ts
            SET
                stop_type = freq.mode_category,
                weekly_departures = freq.departures_06_20_total * 5,
                routes_count = freq.distinct_routes
            FROM (
                SELECT gtfs_stop_id,
                       mode_category,
                       departures_06_20_total,
                       distinct_routes,
                       ROW_NUMBER() OVER (PARTITION BY gtfs_stop_id ORDER BY departures_06_20_total DESC) as rn
                FROM transit_stop_frequencies
                WHERE day_type = 'weekday'
            ) freq
            WHERE ts.gtfs_stop_id = freq.gtfs_stop_id
              AND freq.rn = 1
        ");

        $this->info("  Updated {$updated} stops with frequency data");
    }

    private function assignDesoCodes(): void
    {
        $this->info('Assigning DeSO codes via spatial join...');

        $updated = DB::update('
            UPDATE transit_stops ts
            SET deso_code = da.deso_code
            FROM deso_areas da
            WHERE ST_Contains(da.geom, ts.geom)
              AND ts.deso_code IS NULL
        ');

        $unassigned = DB::table('transit_stops')->whereNull('deso_code')->count();
        $this->info("  Assigned {$updated} stops to DeSO areas ({$unassigned} unassigned)");
    }

    private function insertTransitPois(): int
    {
        $this->info('Inserting qualifying transit stops as POIs...');

        $now = now();
        $count = 0;

        // Tier 1: Major rail stations (intercity, >20 departures/day)
        $majorRail = DB::table('transit_stops as ts')
            ->join('transit_stop_frequencies as tsf', 'ts.gtfs_stop_id', '=', 'tsf.gtfs_stop_id')
            ->where('ts.source', 'gtfs')
            ->where('tsf.mode_category', 'rail')
            ->where('tsf.departures_06_20_total', '>=', self::TIER_1_MIN_DEPARTURES)
            ->where('tsf.day_type', 'weekday')
            ->whereNull('ts.parent_station')
            ->select('ts.*', 'tsf.departures_06_20_total', 'tsf.distinct_routes', 'tsf.mode_category')
            ->get();

        $count += $this->upsertPois($majorRail, 'rail_station', 1, $now);

        // Tier 2: Commuter rail + subway stations
        $commuterSubway = DB::table('transit_stops as ts')
            ->join('transit_stop_frequencies as tsf', 'ts.gtfs_stop_id', '=', 'tsf.gtfs_stop_id')
            ->where('ts.source', 'gtfs')
            ->whereIn('tsf.mode_category', ['rail', 'subway'])
            ->where('tsf.departures_06_20_total', '>=', 1)
            ->where('tsf.departures_06_20_total', '<', self::TIER_1_MIN_DEPARTURES)
            ->where('tsf.day_type', 'weekday')
            ->whereNull('ts.parent_station')
            ->select('ts.*', 'tsf.departures_06_20_total', 'tsf.distinct_routes', 'tsf.mode_category')
            ->get();

        // Also include subway stations with any departure count
        $subwayAll = DB::table('transit_stops as ts')
            ->join('transit_stop_frequencies as tsf', 'ts.gtfs_stop_id', '=', 'tsf.gtfs_stop_id')
            ->where('ts.source', 'gtfs')
            ->where('tsf.mode_category', 'subway')
            ->where('tsf.departures_06_20_total', '>=', self::TIER_1_MIN_DEPARTURES)
            ->where('tsf.day_type', 'weekday')
            ->whereNull('ts.parent_station')
            ->select('ts.*', 'tsf.departures_06_20_total', 'tsf.distinct_routes', 'tsf.mode_category')
            ->get();

        $count += $this->upsertPois($commuterSubway, 'rail_station', 2, $now);
        $count += $this->upsertPois($subwayAll, 'rail_station', 2, $now);

        // Tier 3: Tram stops
        $tramStops = DB::table('transit_stops as ts')
            ->join('transit_stop_frequencies as tsf', 'ts.gtfs_stop_id', '=', 'tsf.gtfs_stop_id')
            ->where('ts.source', 'gtfs')
            ->where('tsf.mode_category', 'tram')
            ->where('tsf.departures_06_20_total', '>=', 1)
            ->where('tsf.day_type', 'weekday')
            ->whereNull('ts.parent_station')
            ->select('ts.*', 'tsf.departures_06_20_total', 'tsf.distinct_routes', 'tsf.mode_category')
            ->get();

        $count += $this->upsertPois($tramStops, 'tram_stop', 3, $now);

        // Tier 4: High-frequency bus stops (>=56 dep/day = ~4/hour over 14 hours)
        $hfBus = DB::table('transit_stops as ts')
            ->join('transit_stop_frequencies as tsf', 'ts.gtfs_stop_id', '=', 'tsf.gtfs_stop_id')
            ->where('ts.source', 'gtfs')
            ->where('tsf.mode_category', 'bus')
            ->where('tsf.departures_06_20_total', '>=', self::TIER_4_MIN_DEPARTURES)
            ->where('tsf.day_type', 'weekday')
            ->whereNull('ts.parent_station')
            ->select('ts.*', 'tsf.departures_06_20_total', 'tsf.distinct_routes', 'tsf.mode_category')
            ->get();

        $count += $this->upsertPois($hfBus, 'bus_stop_hf', 4, $now);

        $this->info("  Created {$count} transit POIs");

        return $count;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $stops
     */
    private function upsertPois(\Illuminate\Support\Collection $stops, string $category, int $displayTier, \Illuminate\Support\Carbon $now): int
    {
        if ($stops->isEmpty()) {
            return 0;
        }

        $rows = [];
        $seen = [];

        foreach ($stops as $stop) {
            // Avoid duplicates within same category
            $key = $stop->gtfs_stop_id.'_'.$category;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'external_id' => 'gtfs_'.$stop->gtfs_stop_id,
                'source' => 'gtfs',
                'category' => $category,
                'subcategory' => $stop->mode_category,
                'poi_type' => $category,
                'display_tier' => $displayTier,
                'sentiment' => 'positive',
                'name' => $stop->name,
                'lat' => $stop->lat,
                'lng' => $stop->lng,
                'deso_code' => $stop->deso_code,
                'tags' => null,
                'metadata' => json_encode([
                    'departures_weekday' => $stop->departures_06_20_total,
                    'distinct_routes' => $stop->distinct_routes,
                    'mode' => $stop->mode_category,
                    'weekly_departures' => $stop->departures_06_20_total * 5,
                ]),
                'status' => 'active',
                'last_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 1000) {
                DB::table('pois')->upsert(
                    $rows,
                    ['source', 'external_id'],
                    ['category', 'subcategory', 'poi_type', 'display_tier', 'sentiment', 'name', 'lat', 'lng', 'deso_code', 'metadata', 'status', 'last_verified_at', 'updated_at']
                );
                $rows = [];
            }
        }

        if (! empty($rows)) {
            DB::table('pois')->upsert(
                $rows,
                ['source', 'external_id'],
                ['category', 'subcategory', 'poi_type', 'display_tier', 'sentiment', 'name', 'lat', 'lng', 'deso_code', 'metadata', 'status', 'last_verified_at', 'updated_at']
            );
        }

        // Set PostGIS geometry for new POIs
        DB::update("
            UPDATE pois
            SET geom = ST_SetSRID(ST_MakePoint(lng, lat), 4326)
            WHERE source = 'gtfs'
              AND category = ?
              AND (geom IS NULL OR ST_X(geom) != lng OR ST_Y(geom) != lat)
        ", [$category]);

        $count = count($seen);
        $this->info("    {$category} (tier {$displayTier}): {$count} POIs");

        return $count;
    }
}
