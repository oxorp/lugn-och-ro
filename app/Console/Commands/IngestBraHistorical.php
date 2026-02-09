<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Services\BraDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class IngestBraHistorical extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:bra-historical
        {--from=2019 : Start year}
        {--to=2024 : End year}
        {--file= : Path to BRÅ SOL Excel export with kommun-level data}
        {--national-file= : Path to national 10-year Excel for category proportions}';

    protected $description = 'Ingest historical BRÅ crime data from SOL Excel export (kommun × year)';

    private const DEFAULT_NATIONAL_PATH = 'data/raw/bra/anmalda_brott_10_ar.xlsx';

    public function handle(BraDataService $braService): int
    {
        $fromYear = (int) $this->option('from');
        $toYear = (int) $this->option('to');

        $filePath = $this->option('file');
        if (! $filePath) {
            $this->error('--file is required. Download the BRÅ SOL export (Table 120, kommun level, all years) and provide the file path.');
            $this->info('Instructions:');
            $this->info('  1. Go to https://statistik.bra.se/solwebb/action/index');
            $this->info('  2. Select Table 120 (Anmälda brott per kommun)');
            $this->info('  3. Select crime type: SAMTLIGA BROTT');
            $this->info('  4. Select years: '.implode(', ', range($fromYear, $toYear)));
            $this->info('  5. Select areas: All kommuner');
            $this->info('  6. Export as Excel');
            $this->info('  7. Place file in storage/app/data/raw/bra/ and re-run with --file=<path>');

            return self::FAILURE;
        }

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $this->startIngestionLog('bra', 'ingest:bra-historical');
        $this->addStat('from_year', $fromYear);
        $this->addStat('to_year', $toYear);

        // Parse national proportions for category estimation
        $nationalPath = $this->option('national-file') ?: storage_path('app/'.self::DEFAULT_NATIONAL_PATH);
        $nationalData = null;
        if (file_exists($nationalPath)) {
            $this->info('Parsing national-level Excel for category proportions...');
            $nationalData = $braService->parseNationalExcel($nationalPath);
        } else {
            $this->warn('National Excel not found — using hardcoded 2024 proportions for all years.');
        }

        // Build kommun name → code mapping
        $kommunMap = $braService->getKommunNameToCodeMap();
        $this->info('Known kommuner in DeSO data: '.count($kommunMap));

        // Parse the SOL export
        $this->info("Parsing BRÅ SOL export: {$filePath}");
        $kommunYearData = $this->parseSolExport($filePath, $fromYear, $toYear);

        if (empty($kommunYearData)) {
            $this->error('No data parsed from Excel. Check the file format.');
            $this->failIngestionLog('No data parsed');

            return self::FAILURE;
        }

        $this->info('Parsed data for '.count($kommunYearData).' kommun × year combinations.');

        $records = [];
        $matched = 0;
        $unmatched = [];

        foreach ($kommunYearData as $entry) {
            $name = $entry['municipality_name'];
            $year = $entry['year'];
            $code = $kommunMap[$name] ?? null;

            if (! $code) {
                $unmatched[$name] = true;

                continue;
            }

            $matched++;
            $totalRate = $entry['rate_per_100k'];
            $totalCount = $entry['reported_count'];
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
                'data_source' => 'bra_sol_historical',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Estimated category rates using year-specific national proportions
            if ($totalRate) {
                $proportions = $braService->getNationalProportions($year, $nationalData);
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
            $this->warn('Unmatched kommuner ('.count($unmatched).'): '.implode(', ', array_slice(array_keys($unmatched), 0, 10)));
        }

        // Upsert
        $this->info('Upserting '.count($records).' records...');

        foreach (array_chunk($records, 1000) as $chunk) {
            DB::table('crime_statistics')->upsert(
                $chunk,
                ['municipality_code', 'year', 'crime_category'],
                ['municipality_name', 'reported_count', 'rate_per_100k', 'population', 'data_source', 'updated_at']
            );
        }

        // Report per-year stats
        for ($y = $fromYear; $y <= $toYear; $y++) {
            $count = DB::table('crime_statistics')->where('year', $y)->count();
            $this->info("  Year {$y}: {$count} records in DB");
        }

        $this->processed = count($kommunYearData);
        $this->created = count($records);
        $this->addStat('matched', $matched);
        $this->addStat('unmatched', count($unmatched));
        $this->addStat('total_records', count($records));
        $this->completeIngestionLog();

        return self::SUCCESS;
    }

    /**
     * Parse the BRÅ SOL Excel export.
     *
     * Expected format: Kommun rows × year columns with counts and/or rates.
     * The exact layout depends on the SOL export configuration.
     *
     * @return list<array{municipality_name: string, year: int, reported_count: int|null, rate_per_100k: float|null}>
     */
    private function parseSolExport(string $filePath, int $fromYear, int $toYear): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $results = [];

        // Strategy: find year columns in the header rows (typically row 1-4)
        // SOL exports vary but typically have kommun names in column A/B and year data in subsequent columns
        $yearColumns = $this->findYearColumnsInSheet($sheet, $fromYear, $toYear);

        if (empty($yearColumns)) {
            $this->warn('Could not find year columns in standard positions. Trying alternative formats...');
            $yearColumns = $this->findYearColumnsAlternative($sheet, $fromYear, $toYear);
        }

        if (empty($yearColumns)) {
            $this->error('Could not identify year columns in the Excel file.');
            $this->info('Please ensure the export has year labels in a header row and kommun names in column A or B.');

            return [];
        }

        $this->info('Found year columns: '.json_encode(array_map(fn ($y, $c) => "{$y}={$c}", array_keys($yearColumns), $yearColumns)));

        // Parse data rows
        $kommunColumn = $this->findKommunColumn($sheet);

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $kommunName = trim((string) $sheet->getCell($kommunColumn.$row)->getValue());

            if (empty($kommunName) || str_starts_with($kommunName, 'Hela') || str_starts_with($kommunName, 'Totalt') || str_starts_with($kommunName, 'Samtliga')) {
                continue;
            }

            foreach ($yearColumns as $year => $colInfo) {
                $countCol = $colInfo['count'] ?? null;
                $rateCol = $colInfo['rate'] ?? null;

                $count = null;
                $rate = null;

                if ($countCol) {
                    $val = $sheet->getCell($countCol.$row)->getValue();
                    $count = $this->parseNumericValue($val);
                }

                if ($rateCol) {
                    $val = $sheet->getCell($rateCol.$row)->getValue();
                    $rate = $this->parseNumericValue($val);
                }

                if ($count === null && $rate === null) {
                    continue;
                }

                $results[] = [
                    'municipality_name' => $kommunName,
                    'year' => $year,
                    'reported_count' => $count !== null ? (int) $count : null,
                    'rate_per_100k' => $rate,
                ];
            }
        }

        return $results;
    }

    /**
     * Find year columns by scanning header rows for year numbers.
     * SOL exports typically use: Column for count, next column for rate.
     *
     * @return array<int, array{count: string|null, rate: string|null}>
     */
    private function findYearColumnsInSheet($sheet, int $fromYear, int $toYear): array
    {
        $yearColumns = [];

        // Scan first 5 header rows for year numbers
        for ($headerRow = 1; $headerRow <= 5; $headerRow++) {
            for ($col = 'B'; $col <= 'ZZ'; $col++) {
                $val = $sheet->getCell($col.$headerRow)->getValue();

                if ($val === null) {
                    continue;
                }

                $val = trim((string) $val);

                // Direct year match
                if (is_numeric($val) && (int) $val >= $fromYear && (int) $val <= $toYear) {
                    $year = (int) $val;
                    if (! isset($yearColumns[$year])) {
                        // Check if next column is a rate column
                        $nextCol = $this->nextColumn($col);
                        $nextHeader = trim((string) $sheet->getCell($nextCol.$headerRow)->getValue());

                        if (str_contains(strtolower($nextHeader), '100') || str_contains(strtolower($nextHeader), 'per')) {
                            $yearColumns[$year] = ['count' => $col, 'rate' => $nextCol];
                        } else {
                            // Check the row above for "Antal" vs "Per 100 000" labels
                            $colAbove = trim((string) $sheet->getCell($col.($headerRow - 1))->getValue());
                            if (str_contains(strtolower($colAbove), '100') || str_contains(strtolower($colAbove), 'per')) {
                                $yearColumns[$year] = ['count' => null, 'rate' => $col];
                            } else {
                                $yearColumns[$year] = ['count' => $col, 'rate' => null];
                            }
                        }
                    }
                }
            }

            if (! empty($yearColumns)) {
                break;
            }
        }

        ksort($yearColumns);

        return $yearColumns;
    }

    /**
     * Alternative format: year + "Antal" / "Per 100 000" in separate rows.
     *
     * @return array<int, array{count: string|null, rate: string|null}>
     */
    private function findYearColumnsAlternative($sheet, int $fromYear, int $toYear): array
    {
        $yearColumns = [];

        // Try semicolon CSV import style: years in row 1, subtypes in row 2
        for ($col = 'B'; $col <= 'ZZ'; $col++) {
            for ($headerRow = 1; $headerRow <= 3; $headerRow++) {
                $val = trim((string) $sheet->getCell($col.$headerRow)->getValue());

                if (is_numeric($val) && (int) $val >= $fromYear && (int) $val <= $toYear) {
                    $year = (int) $val;

                    // Look at row below for Antal/Per 100 000
                    $subLabel = strtolower(trim((string) $sheet->getCell($col.($headerRow + 1))->getValue()));

                    if (str_contains($subLabel, 'antal')) {
                        $yearColumns[$year]['count'] = $col;
                    } elseif (str_contains($subLabel, '100')) {
                        $yearColumns[$year]['rate'] = $col;
                    } else {
                        // Default: assume count
                        $yearColumns[$year]['count'] = $col;
                    }
                }
            }
        }

        // Fill in missing keys
        foreach ($yearColumns as &$cols) {
            $cols += ['count' => null, 'rate' => null];
        }

        ksort($yearColumns);

        return $yearColumns;
    }

    private function findKommunColumn($sheet): string
    {
        // Check first 3 rows to find which column has kommun names
        for ($col = 'A'; $col <= 'C'; $col++) {
            for ($row = 1; $row <= 5; $row++) {
                $val = strtolower(trim((string) $sheet->getCell($col.$row)->getValue()));
                if (str_contains($val, 'kommun') || str_contains($val, 'region') || str_contains($val, 'område')) {
                    return $col;
                }
            }
        }

        return 'A';
    }

    private function nextColumn(string $col): string
    {
        return chr(ord($col) + 1);
    }

    private function parseNumericValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '..' || $value === '-' || $value === '.') {
            return null;
        }

        // Handle Swedish number format
        $value = str_replace([' ', "\xC2\xA0"], '', $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }
}
