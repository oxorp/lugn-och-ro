<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class GenerateHeatmapTiles extends Command
{
    protected $signature = 'generate:heatmap-tiles
        {--year=2024 : Score year to render}
        {--zoom-min=5 : Minimum zoom level}
        {--zoom-max=12 : Maximum zoom level}';

    protected $description = 'Generate heatmap PNG tiles from H3 scores';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $zoomMin = (int) $this->option('zoom-min');
        $zoomMax = (int) $this->option('zoom-max');

        $this->info("Generating heatmap tiles for year {$year}, zoom {$zoomMin}-{$zoomMax}...");

        $outputDir = storage_path('app/public/tiles');
        $scriptPath = base_path('scripts/generate_heatmap_tiles.py');

        if (! file_exists($scriptPath)) {
            $this->error("Python script not found: {$scriptPath}");

            return self::FAILURE;
        }

        $dbConfig = config('database.connections.pgsql');

        $command = implode(' ', [
            'python3',
            escapeshellarg($scriptPath),
            "--year={$year}",
            "--zoom-min={$zoomMin}",
            "--zoom-max={$zoomMax}",
            '--output='.escapeshellarg($outputDir),
            '--db-host='.escapeshellarg($dbConfig['host']),
            '--db-port='.escapeshellarg($dbConfig['port']),
            '--db-name='.escapeshellarg($dbConfig['database']),
            '--db-user='.escapeshellarg($dbConfig['username']),
            '--db-password='.escapeshellarg($dbConfig['password']),
        ]);

        $result = Process::timeout(1800)->run($command, function (string $type, string $output): void {
            $this->getOutput()->write($output);
        });

        if (! $result->successful()) {
            $this->error('Tile generation failed with exit code '.$result->exitCode());
            $this->error($result->errorOutput());

            return self::FAILURE;
        }

        $this->info('Tile generation complete.');

        return self::SUCCESS;
    }
}
