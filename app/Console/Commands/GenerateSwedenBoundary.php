<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSwedenBoundary extends Command
{
    protected $signature = 'generate:sweden-boundary {--tolerance=0.005 : Simplification tolerance in degrees}';

    protected $description = 'Generate simplified Sweden boundary GeoJSON for map mask overlay';

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');

        $this->info("Generating Sweden boundary with tolerance {$tolerance}°...");

        $result = DB::selectOne('
            SELECT
                ST_AsGeoJSON(
                    ST_Simplify(ST_Union(geom), ?),
                    6
                ) as geojson,
                ST_NPoints(ST_Simplify(ST_Union(geom), ?)) as vertex_count
            FROM deso_areas
        ', [$tolerance, $tolerance]);

        if (! $result || ! $result->geojson) {
            $this->error('Failed to generate boundary — no DeSO geometry found.');

            return self::FAILURE;
        }

        $geometry = json_decode($result->geojson, true);

        $feature = [
            'type' => 'Feature',
            'properties' => ['name' => 'Sweden'],
            'geometry' => $geometry,
        ];

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [$feature],
        ];

        $path = public_path('data/sweden-boundary.geojson');
        file_put_contents($path, json_encode($geojson));

        $size = round(filesize($path) / 1024);
        $this->info("Saved to {$path}");
        $this->info("Geometry type: {$geometry['type']}");
        $this->info("Vertex count: {$result->vertex_count}");
        $this->info("File size: {$size} KB");

        return self::SUCCESS;
    }
}
