<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportDesoAreas extends Command
{
    protected $signature = 'import:deso-areas
        {--fresh : Truncate the table before importing}
        {--cache-only : Only use cached file, do not download}';

    protected $description = 'Download and import DeSO boundary data from SCB into PostGIS';

    private const WFS_URL = 'https://geodata.scb.se/geoserver/stat/wfs';

    private const CACHE_PATH = 'geodata/deso_2025.geojson';

    private const BATCH_SIZE = 100;

    public function handle(): int
    {
        $this->info('Starting DeSO area import...');

        if ($this->option('fresh')) {
            $this->warn('Truncating deso_areas table...');
            DB::table('deso_areas')->truncate();
        }

        $geojsonPath = $this->ensureGeojsonFile();
        if (! $geojsonPath) {
            $this->error('Failed to obtain GeoJSON file.');

            return self::FAILURE;
        }

        $this->info('Parsing GeoJSON file...');
        ini_set('memory_limit', '1G');
        $geojson = json_decode(file_get_contents($geojsonPath), true);

        if (! $geojson || ! isset($geojson['features'])) {
            $this->error('Invalid GeoJSON structure.');

            return self::FAILURE;
        }

        $features = $geojson['features'];
        $total = count($features);
        $this->info("Found {$total} features to import.");

        $imported = 0;

        DB::beginTransaction();

        try {
            foreach (array_chunk($features, self::BATCH_SIZE) as $chunk) {
                foreach ($chunk as $feature) {
                    $this->importFeature($feature);
                    $imported++;
                }

                if ($imported % 500 === 0 || $imported === $total) {
                    $this->info("Imported {$imported}/{$total} DeSO areas...");
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $count = DB::table('deso_areas')->count();
        $this->info("Import complete. {$count} DeSO areas in database.");

        $this->generateStaticGeojson();

        return self::SUCCESS;
    }

    private function generateStaticGeojson(): void
    {
        $this->info('Generating static GeoJSON file...');

        $outputPath = public_path('data/deso.geojson');
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($outputPath, 'w');
        fwrite($handle, '{"type":"FeatureCollection","features":[');

        $first = true;

        DB::table('deso_areas')
            ->whereNotNull('geom')
            ->select('deso_code', 'deso_name', 'kommun_code', 'kommun_name', 'lan_code', 'lan_name', 'area_km2')
            ->selectRaw('ST_AsGeoJSON(ST_Buffer(geom, 0.00005)) as geometry')
            ->orderBy('deso_code')
            ->chunk(500, function ($rows) use ($handle, &$first) {
                foreach ($rows as $f) {
                    $feature = json_encode([
                        'type' => 'Feature',
                        'geometry' => json_decode($f->geometry),
                        'properties' => [
                            'deso_code' => $f->deso_code,
                            'deso_name' => $f->deso_name,
                            'kommun_code' => $f->kommun_code,
                            'kommun_name' => $f->kommun_name,
                            'lan_code' => $f->lan_code,
                            'lan_name' => $f->lan_name,
                            'area_km2' => $f->area_km2,
                        ],
                    ]);

                    if (! $first) {
                        fwrite($handle, ',');
                    }
                    fwrite($handle, $feature);
                    $first = false;
                }
            });

        fwrite($handle, ']}');
        fclose($handle);

        $sizeMb = round(filesize($outputPath) / 1024 / 1024, 1);
        $this->info("Static GeoJSON written to public/data/deso.geojson ({$sizeMb} MB).");
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

        // Try DeSO 2025 first, fall back to 2018
        foreach (['stat:DeSO_2025', 'stat:DeSO_2018'] as $typeName) {
            $this->info("Downloading from SCB WFS ({$typeName})...");
            $this->info('This may take a few minutes for the full dataset...');

            $response = Http::timeout(600)
                ->connectTimeout(30)
                ->get(self::WFS_URL, [
                    'service' => 'WFS',
                    'version' => '1.1.0',
                    'request' => 'GetFeature',
                    'typeName' => $typeName,
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

            $this->warn("Failed to download {$typeName}, trying fallback...");
        }

        return null;
    }

    private function importFeature(array $feature): void
    {
        $props = $feature['properties'] ?? [];
        $geometry = $feature['geometry'] ?? null;

        if (! $geometry) {
            return;
        }

        // Handle various property name conventions from SCB
        $desoCode = $props['desokod'] ?? $props['deso'] ?? $props['Deso'] ?? $props['DESO'] ?? $props['deso_kod'] ?? null;
        $kommunCode = $props['kommunkod'] ?? $props['kommun'] ?? $props['Kommun'] ?? $props['KOMMUN'] ?? null;
        $lanCode = $props['lanskod'] ?? $props['lan'] ?? $props['Lan'] ?? $props['LAN'] ?? null;
        $desoName = $props['deso_namn'] ?? $props['namn'] ?? $props['Namn'] ?? null;
        $kommunName = $props['kommunnamn'] ?? $props['Kommunnamn'] ?? null;
        $lanName = $props['lannamn'] ?? $props['Lannamn'] ?? null;

        if (! $desoCode) {
            return;
        }

        // Derive kommun and län codes from DeSO code if not in properties
        // DeSO code format: "0114A0010" where first 4 chars = kommun, first 2 = län
        if (! $kommunCode && strlen($desoCode) >= 4) {
            $kommunCode = substr($desoCode, 0, 4);
        }
        if (! $lanCode && strlen($desoCode) >= 2) {
            $lanCode = substr($desoCode, 0, 2);
        }

        $geojsonStr = json_encode($geometry);

        DB::statement('
            INSERT INTO deso_areas (deso_code, deso_name, kommun_code, kommun_name, lan_code, lan_name, geom, area_km2, created_at, updated_at)
            VALUES (
                :deso_code,
                :deso_name,
                :kommun_code,
                :kommun_name,
                :lan_code,
                :lan_name,
                ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geojson), 4326)),
                ST_Area(ST_SetSRID(ST_GeomFromGeoJSON(:geojson2), 4326)::geography) / 1000000,
                NOW(),
                NOW()
            )
            ON CONFLICT (deso_code) DO UPDATE SET
                deso_name = EXCLUDED.deso_name,
                kommun_code = EXCLUDED.kommun_code,
                kommun_name = EXCLUDED.kommun_name,
                lan_code = EXCLUDED.lan_code,
                lan_name = EXCLUDED.lan_name,
                geom = EXCLUDED.geom,
                area_km2 = EXCLUDED.area_km2,
                updated_at = NOW()
        ', [
            'deso_code' => $desoCode,
            'deso_name' => $desoName,
            'kommun_code' => $kommunCode,
            'kommun_name' => $kommunName,
            'lan_code' => $lanCode,
            'lan_name' => $lanName,
            'geojson' => $geojsonStr,
            'geojson2' => $geojsonStr,
        ]);
    }
}
