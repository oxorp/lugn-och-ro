<?php

namespace App\Services;

use App\Models\IndicatorValue;
use Illuminate\Support\Facades\Cache;

class SafetyScoreService
{
    /**
     * Safety indicator weights. Crime data dominates when available.
     *
     * @var array<string, array{weight: float, invert: bool}>
     */
    private const SAFETY_SIGNALS = [
        // Direct crime/safety indicators (75% total)
        'crime_violent_rate' => ['weight' => 0.25, 'invert' => true],
        'crime_total_rate' => ['weight' => 0.10, 'invert' => true],
        'perceived_safety' => ['weight' => 0.20, 'invert' => false],
        'vulnerability_flag' => ['weight' => 0.20, 'invert' => true],
        // Socioeconomic proxies (25% total)
        'employment_rate' => ['weight' => 0.10, 'invert' => false],
        'low_economic_standard_pct' => ['weight' => 0.10, 'invert' => true],
        'education_below_secondary_pct' => ['weight' => 0.05, 'invert' => true],
    ];

    /**
     * Returns a safety score 0.0 (worst) to 1.0 (safest) for a DeSO.
     * Uses actual crime indicators when available, with socioeconomic proxies as fallback.
     */
    public function forDeso(string $desoCode, int $year): float
    {
        $cacheKey = "safety_score:{$desoCode}:{$year}";

        return Cache::remember($cacheKey, 600, function () use ($desoCode, $year) {
            return $this->computeForDeso($desoCode, $year);
        });
    }

    /**
     * Compute safety scores for multiple DeSOs at once (bulk operation).
     *
     * @return array<string, float> Keyed by deso_code
     */
    public function forMultipleDesos(array $desoCodes, int $year): array
    {
        $results = [];
        foreach ($desoCodes as $code) {
            $results[$code] = $this->forDeso($code, $year);
        }

        return $results;
    }

    private function computeForDeso(string $desoCode, int $year): float
    {
        $slugs = array_keys(self::SAFETY_SIGNALS);

        $indicators = IndicatorValue::where('deso_code', $desoCode)
            ->where('year', $year)
            ->whereHas('indicator', fn ($q) => $q->whereIn('slug', $slugs))
            ->with('indicator')
            ->get()
            ->keyBy(fn ($iv) => $iv->indicator->slug);

        if ($indicators->isEmpty()) {
            return 0.5; // Default if no data
        }

        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach (self::SAFETY_SIGNALS as $slug => $config) {
            $iv = $indicators->get($slug);
            if (! $iv || $iv->normalized_value === null) {
                continue;
            }

            $value = (float) $iv->normalized_value;

            // Invert negative-direction indicators so higher = safer
            if ($config['invert']) {
                $value = 1.0 - $value;
            }

            $weighted += $value * $config['weight'];
            $totalWeight += $config['weight'];
        }

        if ($totalWeight === 0.0) {
            return 0.5;
        }

        return $weighted / $totalWeight;
    }
}
