<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KronofogdenService
{
    /** Kolada KPI: Skuldsatta invånare 18+ hos Kronofogden, andel (%) */
    private const KPI_DEBT_RATE = 'N00989';

    /** Kolada KPI: Medianskuld hos Kronofogden (SEK) */
    private const KPI_MEDIAN_DEBT = 'N00990';

    /** Kolada KPI: Verkställda vräkningar (avhysningar), antal/100 000 inv */
    private const KPI_EVICTION_RATE = 'U00958';

    private const KOLADA_BASE_URL = 'https://api.kolada.se/v3';

    /**
     * Fetch all Kronofogden-related data from Kolada API for a given year.
     *
     * @return Collection<string, array<string, mixed>> Keyed by municipality_code
     */
    public function fetchFromKolada(int $year): Collection
    {
        $municipalityNames = $this->fetchMunicipalityNames();
        $debtRates = $this->fetchKpiData(self::KPI_DEBT_RATE, $year);
        $medianDebts = $this->fetchKpiData(self::KPI_MEDIAN_DEBT, $year);
        $evictionRates = $this->fetchKpiData(self::KPI_EVICTION_RATE, $year);

        // Build set of actual kommun codes (type "K", exclude "0000" = Riket) from Kolada
        $kommunCodes = $municipalityNames
            ->filter(fn (array $item, string $id) => $item['type'] === 'K' && $id !== '0000')
            ->keys()
            ->flip();

        // Merge all data by municipality code
        $result = collect();

        foreach ($debtRates as $code => $values) {
            if (! isset($kommunCodes[$code])) {
                continue;
            }

            $nameInfo = $municipalityNames->get($code);
            $median = $medianDebts[$code] ?? [];
            $eviction = $evictionRates[$code] ?? [];

            $result[$code] = [
                'municipality_code' => $code,
                'municipality_name' => $nameInfo['title'] ?? null,
                'county_code' => substr($code, 0, 2),
                'year' => $year,
                'indebted_pct' => $values['T'] ?? null,
                'indebted_men_pct' => $values['M'] ?? null,
                'indebted_women_pct' => $values['K'] ?? null,
                'median_debt_sek' => $median['T'] ?? null,
                'eviction_rate_per_100k' => $eviction['T'] ?? null,
                'data_source' => 'kolada',
            ];
        }

        return $result;
    }

    /**
     * Fetch KPI data from Kolada for all municipalities in a given year.
     *
     * @return array<string, array<string, float|null>> municipality_code => [gender => value]
     */
    private function fetchKpiData(string $kpiId, int $year): array
    {
        $url = self::KOLADA_BASE_URL."/data/kpi/{$kpiId}/year/{$year}";

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            Log::warning("Kolada API failed for KPI {$kpiId}, year {$year}: {$response->status()}");

            return [];
        }

        $data = $response->json('values', []);
        $result = [];

        foreach ($data as $entry) {
            $municipalityCode = $entry['municipality'] ?? null;
            if (! $municipalityCode) {
                continue;
            }

            foreach ($entry['values'] ?? [] as $valueEntry) {
                $gender = $valueEntry['gender'] ?? 'T';
                $value = $valueEntry['value'] ?? null;

                if ($value !== null) {
                    $result[$municipalityCode][$gender] = (float) $value;
                }
            }
        }

        return $result;
    }

    /**
     * Fetch municipality names and types from Kolada.
     *
     * @return Collection<string, array{title: string, type: string}>
     */
    private function fetchMunicipalityNames(): Collection
    {
        $response = Http::timeout(30)->get(self::KOLADA_BASE_URL.'/municipality');

        if (! $response->successful()) {
            Log::warning('Failed to fetch municipality list from Kolada');

            return collect();
        }

        return collect($response->json('values', []))
            ->keyBy('id')
            ->map(fn (array $item) => [
                'title' => $item['title'],
                'type' => $item['type'],
            ]);
    }

    /**
     * Check if a code is a valid kommun code (4 digits, not län or national).
     */
    private function isKommunCode(string $code): bool
    {
        // Skip national ("0000"), regional (2-digit län), and group codes ("G...")
        return strlen($code) === 4
            && $code !== '0000'
            && ctype_digit($code);
    }
}
