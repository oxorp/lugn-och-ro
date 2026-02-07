<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Services\BraDataService;
use App\Services\DataValidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IngestBraCrime extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:bra-crime
        {--year=2024 : Year for the data}
        {--file= : Path to kommun CSV file}
        {--national-file= : Path to national 10-year Excel file}';

    protected $description = 'Ingest BRÅ reported crime statistics at kommun level';

    private const DEFAULT_CSV_PATH = 'data/raw/bra/anmalda_brott_kommuner_2025.csv';

    private const DEFAULT_NATIONAL_PATH = 'data/raw/bra/anmalda_brott_10_ar.xlsx';

    public function handle(BraDataService $braService): int
    {
        $year = (int) $this->option('year');

        $this->startIngestionLog('bra', 'ingest:bra-crime');
        $this->addStat('year', $year);

        // Resolve file paths
        $csvPath = $this->option('file') ?: storage_path('app/'.self::DEFAULT_CSV_PATH);
        if (! file_exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");
            $this->info('Place the BRÅ kommun CSV file at: '.storage_path('app/'.self::DEFAULT_CSV_PATH));
            $this->failIngestionLog('CSV file not found');

            return self::FAILURE;
        }

        // Parse national Excel for category proportions
        $nationalPath = $this->option('national-file') ?: storage_path('app/'.self::DEFAULT_NATIONAL_PATH);
        $nationalData = null;
        if (file_exists($nationalPath)) {
            $this->info('Parsing national-level Excel for category proportions...');
            $nationalData = $braService->parseNationalExcel($nationalPath);
        } else {
            $this->warn('National Excel not found — using hardcoded 2024 proportions.');
        }

        $proportions = $braService->getNationalProportions($year, $nationalData);
        $this->info('Category proportions: '.json_encode(array_map(fn ($v) => round($v * 100, 1).'%', $proportions)));

        // Build kommun name → code mapping
        $kommunMap = $braService->getKommunNameToCodeMap();
        $this->info('Known kommuner in DeSO data: '.count($kommunMap));

        // Parse CSV
        $this->info("Parsing kommun CSV: {$csvPath}");
        $kommunData = $braService->parseKommunCsv($csvPath);
        $this->info("Found {$kommunData->count()} kommuner in CSV.");

        $records = [];
        $matched = 0;
        $unmatched = [];

        foreach ($kommunData as $row) {
            $name = $row['municipality_name'];
            $code = $kommunMap[$name] ?? null;

            if (! $code) {
                $unmatched[] = $name;

                continue;
            }

            $matched++;
            $totalRate = $row['rate_per_100k'];
            $totalCount = $row['reported_count'];
            $population = ($totalRate > 0 && $totalCount > 0) ? (int) round($totalCount / $totalRate * 100000) : null;

            // Total crime
            $records[] = [
                'municipality_code' => $code,
                'municipality_name' => $name,
                'year' => $year,
                'crime_category' => 'crime_total',
                'reported_count' => $totalCount,
                'rate_per_100k' => $totalRate,
                'population' => $population,
                'data_source' => 'bra_kommun_csv',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Estimated category rates
            if ($totalRate) {
                $categoryRates = $braService->estimateCategoryRates($totalRate, $proportions);

                foreach (['crime_person', 'crime_theft', 'crime_damage', 'crime_robbery', 'crime_sexual', 'crime_drug'] as $cat) {
                    if (! isset($categoryRates[$cat])) {
                        continue;
                    }

                    $estCount = $population ? (int) round($categoryRates[$cat] * $population / 100000) : null;
                    $records[] = [
                        'municipality_code' => $code,
                        'municipality_name' => $name,
                        'year' => $year,
                        'crime_category' => $cat,
                        'reported_count' => $estCount,
                        'rate_per_100k' => $categoryRates[$cat],
                        'population' => $population,
                        'data_source' => 'bra_estimated_from_national_proportions',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        if (count($unmatched) > 0) {
            $this->addWarning('Unmatched kommuner ('.count($unmatched).'): '.implode(', ', array_slice($unmatched, 0, 10)));
        }

        // Upsert in chunks
        $this->info("Upserting {$matched} kommuner × 7 categories = ".count($records).' records...');

        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('crime_statistics')->upsert(
                $chunk,
                ['municipality_code', 'year', 'crime_category'],
                ['municipality_name', 'reported_count', 'rate_per_100k', 'population', 'data_source', 'updated_at']
            );
        }

        $totalInDb = DB::table('crime_statistics')->where('year', $year)->count();
        $this->info("Done. {$totalInDb} crime statistics records for year {$year}.");

        $this->processed = $kommunData->count();
        $this->created = count($records);
        $this->addStat('matched', $matched);
        $this->addStat('unmatched', count($unmatched));
        $this->addStat('total_records', count($records));
        $this->completeIngestionLog();

        $report = app(DataValidationService::class)->validateIngestion($this->ingestionLog, 'bra', $year);
        $this->info("Validation: {$report->passedCount()} passed, {$report->failedCount()} failed");

        if ($report->hasBlockingFailures()) {
            $this->error('Blocking validation failures detected.');
            $this->error($report->summary());
            $this->ingestionLog->update(['status' => 'completed_with_errors']);

            return self::FAILURE;
        }

        if ($report->hasWarnings()) {
            $this->warn($report->summary());
        }

        return self::SUCCESS;
    }
}
