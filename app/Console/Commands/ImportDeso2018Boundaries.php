<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportDeso2018Boundaries extends Command
{
    use LogsIngestion;

    protected $signature = 'import:deso-2018-boundaries
        {--fresh : Truncate the table before importing}
        {--cache-only : Only use cached file, do not download}';

    protected $description = 'Download and import DeSO 2018 boundary data from SCB WFS into PostGIS';

    private const WFS_URL = 'https://geodata.scb.se/geoserver/stat/wfs';

    private const CACHE_PATH = 'geodata/deso_2018.geojson';

    private const BATCH_SIZE = 100;

    public function handle(): int
    {
        ini_set('memory_limit', '1G');

        $this->startIngestionLog('scb_wfs', 'import:deso-2018-boundaries');
        $this->info('Starting DeSO 2018 boundary import...');

        if ($this->option('fresh')) {
            $this->warn('Truncating deso_areas_2018 table...');
            DB::table('deso_areas_2018')->truncate();
        }

        $geojsonPath = $this->ensureGeojsonFile();
        if (! $geojsonPath) {
            $this->failIngestionLog('Failed to obtain GeoJSON file.');
            $this->error('Failed to obtain GeoJSON file.');

            return self::FAILURE;
        }

        $this->info('Parsing GeoJSON file...');
        ini_set('memory_limit', '1G');
        $geojson = json_decode(file_get_contents($geojsonPath), true);

        if (! $geojson || ! isset($geojson['features'])) {
            $this->failIngestionLog('Invalid GeoJSON structure.');
            $this->error('Invalid GeoJSON structure.');

            return self::FAILURE;
        }

        $features = $geojson['features'];
        $total = count($features);
        $this->info("Found {$total} features to import.");

        DB::beginTransaction();

        try {
            foreach (array_chunk($features, self::BATCH_SIZE) as $chunk) {
                foreach ($chunk as $feature) {
                    $this->importFeature($feature);
                    $this->processed++;
                }

                if ($this->processed % 500 === 0 || $this->processed === $total) {
                    $this->info("Imported {$this->processed}/{$total} DeSO 2018 areas...");
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->failIngestionLog($e->getMessage());
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $count = DB::table('deso_areas_2018')->count();
        $this->info("Import complete. {$count} DeSO 2018 areas in database.");

        $this->addStat('total_areas', $count);
        $this->created = $count;
        $this->completeIngestionLog();

        return self::SUCCESS;
    }

    private function ensureGeojsonFile(): ?string
    {
        $storagePath = storage_path('app/'.self::CACHE_PATH);

        if (file_exists($storagePath)) {
            $this->info('Using cached GeoJSON file.');

            return $storagePath;
        }

        if ($this->option('cache-only')) {
            $this->error('No cached file found and --cache-only was specified.');

            return null;
        }

        $dir = dirname($storagePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->info('Downloading DeSO 2018 from SCB WFS (stat:DeSO_2018)...');
        $this->info('This may take a few minutes for the full dataset...');

        $response = Http::timeout(600)
            ->connectTimeout(30)
            ->get(self::WFS_URL, [
                'service' => 'WFS',
                'version' => '1.1.0',
                'request' => 'GetFeature',
                'typeName' => 'stat:DeSO_2018',
                'outputFormat' => 'application/json',
                'srsName' => 'EPSG:4326',
            ]);

        if ($response->successful()) {
            $body = $response->body();
            $decoded = json_decode($body, true);

            if ($decoded && isset($decoded['features']) && count($decoded['features']) > 0) {
                file_put_contents($storagePath, $body);
                $this->info('Downloaded and cached GeoJSON ('.round(strlen($body) / 1024 / 1024, 1).' MB).');

                return $storagePath;
            }
        }

        $this->error('Failed to download DeSO 2018 boundaries from SCB WFS.');

        return null;
    }

    private function importFeature(array $feature): void
    {
        $props = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? null;

        if (! $geometry) {
            $this->skipped++;

            return;
        }

        // Handle various property name conventions from SCB
        $desoCode = $props['desokod'] ?? $props['deso'] ?? $props['Deso'] ?? $props['DESO'] ?? $props['deso_kod'] ?? null;
        $kommunCode = $props['kommunkod'] ?? $props['kommun'] ?? $props['Kommun'] ?? $props['KOMMUN'] ?? null;
        $desoName = $props['deso_namn'] ?? $props['namn'] ?? $props['Namn'] ?? null;
        $kommunName = $props['kommunnamn'] ?? $props['Kommunnamn'] ?? null;

        if (! $desoCode) {
            $this->skipped++;

            return;
        }

        // Derive kommun code from DeSO code if not in properties
        if (! $kommunCode && strlen($desoCode) >= 4) {
            $kommunCode = substr($desoCode, 0, 4);
        }

        $geojsonStr = json_encode($geometry);

        DB::statement('
            INSERT INTO deso_areas_2018 (deso_code, deso_name, kommun_code, kommun_name, geom, created_at, updated_at)
            VALUES (
                :deso_code,
                :deso_name,
                :kommun_code,
                :kommun_name,
                ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)),
                NOW(),
                NOW()
            )
            ON CONFLICT (deso_code) DO UPDATE SET
                deso_name = EXCLUDED.deso_name,
                kommun_code = EXCLUDED.kommun_code,
                kommun_name = EXCLUDED.kommun_name,
                geom = EXCLUDED.geom,
                updated_at = NOW()
        ', [
            'deso_code' => $desoCode,
            'deso_name' => $desoName,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'geojson' => $geojsonStr,
        ]);

        $this->created++;
    }
}
