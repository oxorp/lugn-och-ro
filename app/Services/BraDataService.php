<?php

namespace App\Services;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BraDataService
{
    /**
     * National-level crime category proportions for disaggregating kommun totals.
     * Source: BRÅ "Anmälda brott de senaste 10 åren" (2024 data).
     * These represent each category's share of total reported crimes nationally.
     *
     * @var array<string, array{count: int, categories: list<string>}>
     */
    private const NATIONAL_CATEGORY_COUNTS_2024 = [
        'crime_person' => ['count' => 312708, 'categories' => ['Brott mot person']],
        'crime_theft' => ['count' => 348551, 'categories' => ['Stöld, rån m.m.']],
        'crime_damage' => ['count' => 198979, 'categories' => ['Skadegörelsebrott']],
        'crime_robbery' => ['count' => 4760, 'categories' => ['Rån']],
        'crime_sexual' => ['count' => 25879, 'categories' => ['Sexualbrott']],
        'crime_drug' => ['count' => 130297, 'categories' => ['Narkotikabrott']],
    ];

    private const NATIONAL_TOTAL_2024 = 1489319;

    /**
     * Parse BRÅ kommun-level CSV file.
     * Format: Kommun;Antal (prel.);Per 100 000 inv. (prel.)
     *
     * @return Collection<int, array{municipality_name: string, reported_count: int, rate_per_100k: float}>
     */
    public function parseKommunCsv(string $filePath): Collection
    {
        $rows = collect();
        $handle = fopen($filePath, 'r');

        if (! $handle) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Skip header
        fgetcsv($handle, 0, ';');

        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            if (count($line) < 3 || empty($line[0])) {
                continue;
            }

            $name = trim($line[0]);
            $count = $this->parseNumericValue($line[1]);
            $rate = $this->parseNumericValue($line[2]);

            if ($count === null && $rate === null) {
                continue;
            }

            $rows->push([
                'municipality_name' => $name,
                'reported_count' => $count,
                'rate_per_100k' => $rate,
            ]);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Parse BRÅ national-level Excel for crime category breakdown.
     *
     * @return array<string, array<int, int>> Category slug => [year => count]
     */
    public function parseNationalExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);

        $categoryTargets = [
            'SAMTLIGA BROTT' => 'crime_total',
            'Brott mot person, totalt' => 'crime_person',
            'Stöld, rån m.m. totalt' => 'crime_theft',
            'Skadegörelsebrott, totalt' => 'crime_damage',
            'Rån, totalt' => 'crime_robbery',
            'Sexualbrott, totalt' => 'crime_sexual',
            'Narkotikastrafflagen, totalt' => 'crime_drug',
        ];

        // Row 2 has year headers in columns C-L (2015-2024)
        $yearColumns = [];
        for ($col = 'C'; $col <= 'L'; $col++) {
            $yearVal = $sheet->getCell($col.'2')->getValue();
            if ($yearVal !== null && is_numeric($yearVal)) {
                $yearColumns[(int) $yearVal] = $col;
            }
        }

        $results = [];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $bVal = trim((string) $sheet->getCell('B'.$row)->getValue());

            if (! isset($categoryTargets[$bVal])) {
                continue;
            }

            $slug = $categoryTargets[$bVal];
            $results[$slug] = [];

            foreach ($yearColumns as $year => $col) {
                $val = $sheet->getCell($col.$row)->getValue();
                if (is_numeric($val)) {
                    $results[$slug][$year] = (int) $val;
                }
            }
        }

        return $results;
    }

    /**
     * Get national crime category proportions for a given year.
     * Each category's share of total crimes nationally.
     *
     * @return array<string, float> Category slug => proportion (0.0-1.0)
     */
    public function getNationalProportions(int $year, ?array $nationalData = null): array
    {
        if ($nationalData && isset($nationalData['crime_total'][$year])) {
            $total = $nationalData['crime_total'][$year];
            $proportions = [];

            foreach ($nationalData as $slug => $years) {
                if ($slug === 'crime_total') {
                    continue;
                }
                if (isset($years[$year]) && $total > 0) {
                    $proportions[$slug] = $years[$year] / $total;
                }
            }

            return $proportions;
        }

        // Fallback to hardcoded 2024 proportions
        $proportions = [];
        foreach (self::NATIONAL_CATEGORY_COUNTS_2024 as $slug => $data) {
            $proportions[$slug] = $data['count'] / self::NATIONAL_TOTAL_2024;
        }

        return $proportions;
    }

    /**
     * Estimate kommun-level category rates from total rate using national proportions.
     *
     * @return array<string, float> Category slug => estimated rate per 100k
     */
    public function estimateCategoryRates(float $totalRate, array $proportions): array
    {
        $rates = [];
        foreach ($proportions as $slug => $proportion) {
            $rates[$slug] = round($totalRate * $proportion, 2);
        }

        // Also compute composite rates
        $rates['crime_violent_rate'] = round(
            ($rates['crime_person'] ?? 0) + ($rates['crime_robbery'] ?? 0) + ($rates['crime_sexual'] ?? 0),
            2
        );

        $rates['crime_property_rate'] = round(
            ($rates['crime_theft'] ?? 0) + ($rates['crime_damage'] ?? 0),
            2
        );

        return $rates;
    }

    /**
     * Map a kommun name to its 4-digit code using DeSO area data.
     *
     * @return array<string, string> Name => code mapping
     */
    public function getKommunNameToCodeMap(): array
    {
        return \Illuminate\Support\Facades\DB::table('deso_areas')
            ->select('kommun_code', 'kommun_name')
            ->distinct()
            ->get()
            ->pluck('kommun_code', 'kommun_name')
            ->toArray();
    }

    private function parseNumericValue(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || $value === '..' || $value === '-' || $value === '.') {
            return null;
        }

        // Handle Swedish number format: space as thousands separator, comma as decimal
        $value = str_replace([' ', "\xC2\xA0"], '', $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }
}
