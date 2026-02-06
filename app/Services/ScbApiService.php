<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScbApiService
{
    private const BASE_URL = 'https://api.scb.se/OV0104/v1/doris/en/ssd';

    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_MS = 2000;

    /**
     * Indicator slug => SCB API configuration.
     *
     * @var array<string, array{path: string, contents_code: string, extra_query: array<int, array{code: string, selection: array{filter: string, values: string[]}}>, value_transform: string}>
     */
    private array $indicatorConfig = [
        'median_income' => [
            'path' => 'HE/HE0110/HE0110I/Tab3InkDesoRegso',
            'contents_code' => '000008AB',
            'extra_query' => [],
            'value_transform' => 'multiply_1000', // SEK thousands -> SEK
        ],
        'low_economic_standard_pct' => [
            'path' => 'HE/HE0110/HE0110I/Tab4InkDesoRegso',
            'contents_code' => '000008AC',
            'extra_query' => [
                ['code' => 'Alder', 'selection' => ['filter' => 'item', 'values' => ['tot']]],
            ],
            'value_transform' => 'none',
        ],
        'foreign_background_pct' => [
            'path' => 'BE/BE0101/BE0101Y/FolkmDesoBakgrKon',
            'contents_code' => '000007Y4',
            'extra_query' => [
                ['code' => 'UtlBakgrund', 'selection' => ['filter' => 'item', 'values' => ['1', 'SA']]],
                ['code' => 'Kon', 'selection' => ['filter' => 'item', 'values' => ['1+2']]],
            ],
            'value_transform' => 'ratio_first_over_last', // foreign / total
        ],
        'population' => [
            'path' => 'BE/BE0101/BE0101Y/FolkmDesoAldKon',
            'contents_code' => '000007Y7',
            'extra_query' => [
                ['code' => 'Alder', 'selection' => ['filter' => 'item', 'values' => ['totalt']]],
                ['code' => 'Kon', 'selection' => ['filter' => 'item', 'values' => ['1+2']]],
            ],
            'value_transform' => 'none',
        ],
        'employment_rate' => [
            'path' => 'AM/AM0207/AM0207I/BefDeSoSyssN',
            'contents_code' => '00000569',
            'extra_query' => [
                ['code' => 'Sysselsattning', 'selection' => ['filter' => 'item', 'values' => ['FÖRV', 'total']]],
                ['code' => 'Kon', 'selection' => ['filter' => 'item', 'values' => ['1+2']]],
            ],
            'value_transform' => 'ratio_first_over_last', // employed / total
        ],
        'education_post_secondary_pct' => [
            'path' => 'UF/UF0506/UF0506D/UtbSUNBefDesoRegsoN',
            'contents_code' => '000007Z6',
            'extra_query' => [
                ['code' => 'UtbildningsNiva', 'selection' => ['filter' => 'item', 'values' => ['5', '6', '21', '3+4', 'US']]],
            ],
            'value_transform' => 'education_post_secondary',
        ],
        'education_below_secondary_pct' => [
            'path' => 'UF/UF0506/UF0506D/UtbSUNBefDesoRegsoN',
            'contents_code' => '000007Z6',
            'extra_query' => [
                ['code' => 'UtbildningsNiva', 'selection' => ['filter' => 'item', 'values' => ['5', '6', '21', '3+4', 'US']]],
            ],
            'value_transform' => 'education_below_secondary',
        ],
        'rental_tenure_pct' => [
            'path' => 'BO/BO0104/BO0104X/BO0104T01N2',
            'contents_code' => '00000864',
            'extra_query' => [
                ['code' => 'Upplatelseform', 'selection' => ['filter' => 'item', 'values' => ['1', '2', '3', 'ÖVRIGT']]],
            ],
            'value_transform' => 'ratio_first_over_sum',
        ],
    ];

    /**
     * @return array{path: string, contents_code: string, extra_query: array<int, array{code: string, selection: array{filter: string, values: string[]}}>, value_transform: string}|null
     */
    public function getIndicatorConfig(string $slug): ?array
    {
        return $this->indicatorConfig[$slug] ?? null;
    }

    /**
     * @return string[]
     */
    public function getAvailableSlugs(): array
    {
        return array_keys($this->indicatorConfig);
    }

    /**
     * Fetch DeSO-level data for a specific indicator and year.
     *
     * @return array<string, float|null> Map of deso_code => value
     */
    public function fetchIndicator(string $slug, int $year): array
    {
        $config = $this->getIndicatorConfig($slug);
        if (! $config) {
            throw new \InvalidArgumentException("Unknown indicator slug: {$slug}");
        }

        $query = $this->buildQuery($config, $year);
        $url = self::BASE_URL.'/'.$config['path'];

        $response = $this->postWithRetry($url, $query);
        $data = $response->json();

        return $this->parseResponse($data, $config['value_transform']);
    }

    /**
     * Find the most recent year with data for an indicator.
     */
    public function findLatestYear(string $slug): ?int
    {
        $config = $this->getIndicatorConfig($slug);
        if (! $config) {
            return null;
        }

        $url = self::BASE_URL.'/'.$config['path'];
        $metadata = Http::timeout(30)->get($url)->json();

        $years = [];
        foreach ($metadata['variables'] ?? [] as $var) {
            if ($var['code'] === 'Tid') {
                $years = array_map('intval', $var['values'] ?? []);
                break;
            }
        }

        return $years ? max($years) : null;
    }

    /**
     * @return array<int, array{code: string, selection: array{filter: string, values: string[]}}>
     */
    private function buildQuery(array $config, int $year): array
    {
        $query = [
            ['code' => 'Region', 'selection' => ['filter' => 'all', 'values' => ['*']]],
            ['code' => 'ContentsCode', 'selection' => ['filter' => 'item', 'values' => [$config['contents_code']]]],
            ...$config['extra_query'],
            ['code' => 'Tid', 'selection' => ['filter' => 'item', 'values' => [(string) $year]]],
        ];

        return $query;
    }

    private function postWithRetry(string $url, array $query): \Illuminate\Http\Client\Response
    {
        $body = [
            'query' => $query,
            'response' => ['format' => 'json-stat2'],
        ];

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(120)
                    ->retry(1, 0)
                    ->post($url, $body);

                if ($response->successful()) {
                    return $response;
                }

                Log::warning("SCB API returned {$response->status()} on attempt {$attempt}", [
                    'url' => $url,
                    'body' => substr($response->body(), 0, 500),
                ]);
            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning("SCB API request failed on attempt {$attempt}: {$e->getMessage()}");
            }

            if ($attempt < self::MAX_RETRIES) {
                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
            }
        }

        throw $lastException ?? new \RuntimeException('SCB API request failed after '.self::MAX_RETRIES.' attempts');
    }

    /**
     * Parse JSON-stat2 response into [deso_code => value] array.
     *
     * @return array<string, float|null>
     */
    private function parseResponse(array $data, string $transform): array
    {
        $dimensions = $data['id'] ?? [];
        $sizes = $data['size'] ?? [];
        $values = $data['value'] ?? [];
        $dimensionMeta = $data['dimension'] ?? [];

        $regionDimIndex = array_search('Region', $dimensions);
        if ($regionDimIndex === false) {
            throw new \RuntimeException('No Region dimension in SCB response');
        }

        $regionIndex = $dimensionMeta['Region']['category']['index'] ?? [];

        // Build a map to understand how dimensions are laid out
        // Values array is a flat cartesian product of all dimensions
        $strides = $this->computeStrides($sizes);

        // Group values by region, collecting all values for each region.
        // Track which codes came from DeSO2025 suffix to prefer them over old codes.
        $regionValues = [];
        $isDeso2025 = [];
        foreach ($regionIndex as $regionCode => $regionPos) {
            $hasSuffix = str_contains($regionCode, '_DeSO2025');
            $desoCode = $this->extractDesoCode($regionCode);
            if (! $desoCode) {
                continue;
            }

            // Prefer DeSO2025 codes over old codes when both exist
            if (isset($regionValues[$desoCode]) && ($isDeso2025[$desoCode] ?? false) && ! $hasSuffix) {
                continue;
            }

            $regionValues[$desoCode] = $this->extractRegionValues(
                $values,
                $sizes,
                $strides,
                $regionDimIndex,
                $regionPos
            );
            $isDeso2025[$desoCode] = $hasSuffix;
        }

        return $this->applyTransform($regionValues, $transform);
    }

    /**
     * Compute strides for navigating the flat value array.
     *
     * @return int[]
     */
    private function computeStrides(array $sizes): array
    {
        $strides = [];
        $stride = 1;
        for ($i = count($sizes) - 1; $i >= 0; $i--) {
            $strides[$i] = $stride;
            $stride *= $sizes[$i];
        }

        return $strides;
    }

    /**
     * Extract all values for a specific region position.
     *
     * @return array<int|float|null>
     */
    private function extractRegionValues(array $values, array $sizes, array $strides, int $regionDimIndex, int $regionPos): array
    {
        $result = [];
        $totalPerRegion = 1;
        for ($i = 0; $i < count($sizes); $i++) {
            if ($i !== $regionDimIndex) {
                $totalPerRegion *= $sizes[$i];
            }
        }

        // For single contents + single year, this is simple
        if ($totalPerRegion === 1) {
            $idx = $regionPos * $strides[$regionDimIndex];

            return [$values[$idx] ?? null];
        }

        // For multi-dimensional responses, iterate non-region dimensions
        $this->collectValues($values, $sizes, $strides, $regionDimIndex, $regionPos, 0, 0, $result);

        return $result;
    }

    private function collectValues(array $values, array $sizes, array $strides, int $regionDimIndex, int $regionPos, int $dimIdx, int $offset, array &$result): void
    {
        if ($dimIdx === count($sizes)) {
            $result[] = $values[$offset] ?? null;

            return;
        }

        if ($dimIdx === $regionDimIndex) {
            $this->collectValues($values, $sizes, $strides, $regionDimIndex, $regionPos, $dimIdx + 1, $offset + $regionPos * $strides[$dimIdx], $result);
        } else {
            for ($i = 0; $i < $sizes[$dimIdx]; $i++) {
                $this->collectValues($values, $sizes, $strides, $regionDimIndex, $regionPos, $dimIdx + 1, $offset + $i * $strides[$dimIdx], $result);
            }
        }
    }

    private function extractDesoCode(string $regionCode): ?string
    {
        // Strip _DeSO2025 or _RegSO2025 suffix
        $code = preg_replace('/_(?:DeSO|RegSO)\d+$/', '', $regionCode);

        // DeSO codes are 9 characters: 4-digit kommun + letter + 4 digits
        if (preg_match('/^\d{4}[A-Z]\d{4}$/', $code)) {
            return $code;
        }

        // RegSO codes (like 0114R001) - skip these
        return null;
    }

    /**
     * @param  array<string, array<int|float|null>>  $regionValues
     * @return array<string, float|null>
     */
    private function applyTransform(array $regionValues, string $transform): array
    {
        $result = [];

        foreach ($regionValues as $desoCode => $vals) {
            $result[$desoCode] = match ($transform) {
                'none' => $vals[0],
                'multiply_1000' => $vals[0] !== null ? $vals[0] * 1000 : null,
                'ratio_first_over_last' => $this->ratioFirstOverLast($vals),
                'ratio_first_over_sum' => $this->ratioFirstOverSum($vals),
                'education_post_secondary' => $this->educationPostSecondary($vals),
                'education_below_secondary' => $this->educationBelowSecondary($vals),
                default => $vals[0],
            };
        }

        return $result;
    }

    private function ratioFirstOverLast(array $vals): ?float
    {
        $first = $vals[0] ?? null;
        $last = end($vals);
        if ($first === null || $last === null || $last == 0) {
            return null;
        }

        return round(($first / $last) * 100, 2);
    }

    private function ratioFirstOverSum(array $vals): ?float
    {
        $first = $vals[0] ?? null;
        $sum = array_sum(array_filter($vals, fn ($v) => $v !== null));
        if ($first === null || $sum == 0) {
            return null;
        }

        return round(($first / $sum) * 100, 2);
    }

    /**
     * Education levels order: 5 (post-sec <3yr), 6 (post-sec 3yr+), 21 (primary), 3+4 (upper secondary), US (unknown)
     * Post-secondary = levels 5 + 6
     */
    private function educationPostSecondary(array $vals): ?float
    {
        // Values in order: 5, 6, 21, 3+4, US
        if (count($vals) < 5) {
            return null;
        }

        $postSec = ($vals[0] ?? 0) + ($vals[1] ?? 0);
        $total = array_sum(array_filter($vals, fn ($v) => $v !== null));
        if ($total == 0) {
            return null;
        }

        return round(($postSec / $total) * 100, 2);
    }

    /**
     * Below secondary = level 21 (primary and lower secondary)
     */
    private function educationBelowSecondary(array $vals): ?float
    {
        // Values in order: 5, 6, 21, 3+4, US
        if (count($vals) < 5) {
            return null;
        }

        $belowSec = $vals[2] ?? 0;
        $total = array_sum(array_filter($vals, fn ($v) => $v !== null));
        if ($total == 0) {
            return null;
        }

        return round(($belowSec / $total) * 100, 2);
    }
}
