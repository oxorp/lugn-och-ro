<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class IngestNtu extends Command
{
    protected $signature = 'ingest:ntu
        {--year=2025 : NTU survey year to focus on}
        {--file= : Path to NTU län Excel file}';

    protected $description = 'Ingest NTU (Nationella trygghetsundersökningen) survey data at län level';

    private const DEFAULT_FILE = 'data/raw/bra/ntu_lan_2017_2025.xlsx';

    /**
     * Sheet name => our indicator slug mapping.
     * Values in these sheets are % of respondents with that concern.
     *
     * @var array<string, array{slug: string, direction: string}>
     */
    private const SHEET_MAP = [
        'R4.1' => ['slug' => 'ntu_unsafe_night', 'direction' => 'negative'],
        'R4.2' => ['slug' => 'ntu_worry_assault', 'direction' => 'negative'],
        'R4.6' => ['slug' => 'ntu_worry_burglary', 'direction' => 'negative'],
        'R4.11' => ['slug' => 'ntu_worry_crime_society', 'direction' => 'negative'],
    ];

    /**
     * Län name → län code mapping.
     *
     * @var array<string, string>
     */
    private const LAN_CODES = [
        'Stockholms län' => '01',
        'Uppsala län' => '03',
        'Södermanlands län' => '04',
        'Östergötlands län' => '05',
        'Jönköpings län' => '06',
        'Kronobergs län' => '07',
        'Kalmar län' => '08',
        'Gotlands län' => '09',
        'Blekinge län' => '10',
        'Skåne län' => '12',
        'Hallands län' => '13',
        'Västra Götalands län' => '14',
        'Värmlands län' => '17',
        'Örebro län' => '18',
        'Västmanlands län' => '19',
        'Dalarnas län' => '20',
        'Gävleborgs län' => '21',
        'Västernorrlands län' => '22',
        'Jämtlands län' => '23',
        'Västerbottens län' => '24',
        'Norrbottens län' => '25',
    ];

    public function handle(): int
    {
        $targetYear = (int) $this->option('year');

        $log = IngestionLog::query()->create([
            'source' => 'bra_ntu',
            'command' => 'ingest:ntu',
            'status' => 'running',
            'started_at' => now(),
            'metadata' => ['year' => $targetYear],
        ]);

        $filePath = $this->option('file') ?: storage_path('app/'.self::DEFAULT_FILE);
        if (! file_exists($filePath)) {
            $this->error("NTU Excel file not found: {$filePath}");
            $log->update(['status' => 'failed', 'error_message' => 'File not found', 'completed_at' => now()]);

            return self::FAILURE;
        }

        $this->info("Loading NTU Excel: {$filePath}");
        $spreadsheet = IOFactory::load($filePath);

        $records = [];
        $sheetsProcessed = 0;

        foreach (self::SHEET_MAP as $sheetName => $config) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (! $sheet) {
                $this->warn("Sheet {$sheetName} not found, skipping.");

                continue;
            }

            $this->info("Processing sheet {$sheetName} ({$config['slug']})...");

            // Find year columns from row 3
            $yearColumns = $this->findYearColumns($sheet);
            if (empty($yearColumns)) {
                $this->warn("No year columns found in {$sheetName}.");

                continue;
            }

            // Parse data rows — "Samtliga 16–84 år" section (all respondents)
            $inSamtliga = false;
            for ($row = 4; $row <= $sheet->getHighestRow(); $row++) {
                $colA = trim((string) $sheet->getCell('A'.$row)->getValue());
                $colB = trim((string) $sheet->getCell('B'.$row)->getValue());

                // Start of "Samtliga" section
                if (str_contains($colA, 'Samtliga')) {
                    $inSamtliga = true;

                    // National average
                    if ($colB === 'Hela landet') {
                        $records = array_merge($records, $this->extractRowData(
                            $sheet, $row, $yearColumns, $config['slug'], 'national', '00', 'Hela landet'
                        ));
                    }

                    continue;
                }

                // Stop at next demographic section (Män, Kvinnor)
                if ($inSamtliga && $colA !== '' && ! isset(self::LAN_CODES[$colB])) {
                    $inSamtliga = false;

                    continue;
                }

                if (! $inSamtliga) {
                    continue;
                }

                // This is a län row
                $lanName = $colB;
                $lanCode = self::LAN_CODES[$lanName] ?? null;

                if (! $lanCode) {
                    continue;
                }

                $records = array_merge($records, $this->extractRowData(
                    $sheet, $row, $yearColumns, $config['slug'], 'lan', $lanCode, $lanName
                ));
            }

            $sheetsProcessed++;
        }

        if (empty($records)) {
            $this->error('No NTU records extracted.');
            $log->update(['status' => 'failed', 'error_message' => 'No records extracted', 'completed_at' => now()]);

            return self::FAILURE;
        }

        $this->info('Upserting '.count($records).' NTU records...');

        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('ntu_survey_data')->upsert(
                $chunk,
                ['area_code', 'area_type', 'survey_year', 'indicator_slug'],
                ['area_name', 'value', 'confidence_lower', 'confidence_upper', 'data_source', 'reference_year', 'updated_at']
            );
        }

        $totalInDb = DB::table('ntu_survey_data')->count();
        $this->info("Done. {$sheetsProcessed} sheets processed, {$totalInDb} NTU records total in database.");

        $log->update([
            'status' => 'completed',
            'records_processed' => count($records),
            'records_created' => count($records),
            'completed_at' => now(),
            'metadata' => [
                'year' => $targetYear,
                'sheets_processed' => $sheetsProcessed,
                'total_records' => count($records),
            ],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string> year => column letter
     */
    private function findYearColumns($sheet): array
    {
        $yearColumns = [];
        for ($col = 'C'; $col <= 'Z'; $col++) {
            $val = $sheet->getCell($col.'3')->getValue();
            if ($val !== null && is_numeric($val) && (int) $val >= 2015 && (int) $val <= 2030) {
                $yearColumns[(int) $val] = $col;
            }
        }

        return $yearColumns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractRowData($sheet, int $row, array $yearColumns, string $slug, string $areaType, string $areaCode, string $areaName): array
    {
        $records = [];

        foreach ($yearColumns as $year => $col) {
            $value = $sheet->getCell($col.$row)->getValue();
            if ($value === null || $value === '' || $value === '..' || $value === '.') {
                continue;
            }

            $numValue = is_numeric($value) ? round((float) $value, 2) : null;
            if ($numValue === null) {
                continue;
            }

            // Get confidence intervals for the latest year
            $ciLower = null;
            $ciUpper = null;

            // CI columns are typically after the last year column
            $lastYearCol = end($yearColumns);
            $ciLowerCol = chr(ord($lastYearCol) + 1);
            $ciUpperCol = chr(ord($lastYearCol) + 2);

            if ($year === max(array_keys($yearColumns))) {
                $ciLowerVal = $sheet->getCell($ciLowerCol.$row)->getValue();
                $ciUpperVal = $sheet->getCell($ciUpperCol.$row)->getValue();
                $ciLower = is_numeric($ciLowerVal) ? round((float) $ciLowerVal, 2) : null;
                $ciUpper = is_numeric($ciUpperVal) ? round((float) $ciUpperVal, 2) : null;
            }

            $records[] = [
                'area_code' => $areaCode,
                'area_type' => $areaType,
                'area_name' => $areaName,
                'survey_year' => $year,
                'reference_year' => $year - 1,
                'indicator_slug' => $slug,
                'value' => $numValue,
                'confidence_lower' => $ciLower,
                'confidence_upper' => $ciUpper,
                'data_source' => 'bra_ntu_lan_excel',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $records;
    }
}
