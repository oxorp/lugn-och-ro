<?php

namespace App\Services;

use Illuminate\Support\Collection;

class VerdictService
{
    /** @var array<string, array{label: string, indicator_slugs: string[]}> */
    private const CATEGORIES = [
        'safety' => [
            'label' => 'Trygghet & brottslighet',
            'indicator_slugs' => [
                'crime_violent_rate', 'crime_property_rate', 'crime_total_rate',
                'perceived_safety', 'vulnerability_flag',
                'fast_food_density', 'gambling_density', 'pawn_shop_density',
            ],
        ],
        'economy' => [
            'label' => 'Ekonomi & arbetsmarknad',
            'indicator_slugs' => [
                'median_income', 'low_economic_standard_pct', 'employment_rate',
                'debt_rate_pct', 'eviction_rate', 'median_debt_sek',
            ],
        ],
        'education' => [
            'label' => 'Utbildning & skolor',
            'indicator_slugs' => [
                'school_merit_value_avg', 'school_goal_achievement_avg',
                'school_teacher_certification_avg',
                'education_post_secondary_pct', 'education_below_secondary_pct',
            ],
        ],
        'environment' => [
            'label' => 'Miljö & service',
            'indicator_slugs' => [
                'grocery_density', 'healthcare_density', 'restaurant_density',
                'fitness_density', 'transit_stop_density',
            ],
        ],
    ];

    /**
     * Compute verdicts for all categories.
     *
     * @param  array<int, array<string, mixed>>  $indicators
     * @return array<string, array<string, mixed>>
     */
    public function computeAllVerdicts(array $indicators): array
    {
        $verdicts = [];

        foreach (self::CATEGORIES as $key => $config) {
            $verdicts[$key] = $this->computeVerdict($key, $indicators);
        }

        return $verdicts;
    }

    /**
     * Compute verdict for a single category.
     *
     * @param  array<int, array<string, mixed>>  $indicators
     * @return array<string, mixed>
     */
    public function computeVerdict(string $category, array $indicators): array
    {
        $config = self::CATEGORIES[$category] ?? null;

        if (! $config) {
            return $this->emptyVerdict($category);
        }

        $relevant = collect($indicators)
            ->filter(fn (array $i) => in_array($i['slug'], $config['indicator_slugs'], true));

        if ($relevant->isEmpty()) {
            return [
                'label' => $config['label'],
                'score' => null,
                'grade' => "\u{2014}",
                'color' => '#94a3b8',
                'verdict_sv' => 'Inga data tillgängliga för denna kategori.',
                'trend_direction' => 'unknown',
                'indicator_count' => 0,
            ];
        }

        $avgPercentile = $relevant->avg('percentile');

        $avgChange = $relevant
            ->filter(fn (array $i) => ($i['trend']['change_1y'] ?? null) !== null)
            ->avg(fn (array $i) => $i['trend']['change_1y']) ?? 0;

        $grade = $this->percentileToGrade($avgPercentile);
        $trendDir = $avgChange > 1.5 ? 'improving' : ($avgChange < -1.5 ? 'declining' : 'stable');

        return [
            'label' => $config['label'],
            'score' => (int) round($avgPercentile),
            'grade' => $grade['letter'],
            'color' => $grade['color'],
            'verdict_sv' => $this->generateVerdictText($category, $avgPercentile, $trendDir, $relevant),
            'trend_direction' => $trendDir,
            'indicator_count' => $relevant->count(),
        ];
    }

    /**
     * @return array{letter: string, color: string}
     */
    private function percentileToGrade(float $pct): array
    {
        return match (true) {
            $pct >= 80 => ['letter' => 'A', 'color' => '#1a7a2e'],
            $pct >= 60 => ['letter' => 'B', 'color' => '#6abf4b'],
            $pct >= 40 => ['letter' => 'C', 'color' => '#f0c040'],
            $pct >= 20 => ['letter' => 'D', 'color' => '#e57373'],
            default => ['letter' => 'E', 'color' => '#c0392b'],
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $indicators
     */
    private function generateVerdictText(
        string $category,
        float $avgPercentile,
        string $trendDir,
        Collection $indicators
    ): string {
        $trendText = match ($trendDir) {
            'improving' => 'med förbättrad trend senaste året',
            'declining' => 'med försämrad trend senaste året',
            'stable' => 'med stabil trend',
            default => '',
        };

        $levelText = match (true) {
            $avgPercentile >= 80 => 'väl över riksgenomsnittet',
            $avgPercentile >= 60 => 'något över riksgenomsnittet',
            $avgPercentile >= 40 => 'nära riksgenomsnittet',
            $avgPercentile >= 20 => 'under riksgenomsnittet',
            default => 'väl under riksgenomsnittet',
        };

        return match ($category) {
            'safety' => "Tryggheten i området ligger {$levelText} {$trendText}. "
                .$this->safetyDetail($indicators),
            'economy' => "Den ekonomiska situationen ligger {$levelText} {$trendText}. "
                .$this->economyDetail($indicators),
            'education' => "Utbildningsnivån och skolkvaliteten ligger {$levelText} {$trendText}. "
                .$this->educationDetail($indicators),
            'environment' => "Tillgången till service och grönområden ligger {$levelText} {$trendText}. "
                .$this->environmentDetail($indicators),
            default => "Kategorin ligger {$levelText} {$trendText}.",
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $indicators
     */
    private function safetyDetail(Collection $indicators): string
    {
        $parts = [];

        $perceived = $indicators->firstWhere('slug', 'perceived_safety');
        if ($perceived) {
            $level = $perceived['percentile'] >= 60 ? 'god' : ($perceived['percentile'] >= 40 ? 'genomsnittlig' : 'låg');
            $parts[] = "Upplevd trygghet är {$level} ({$perceived['percentile']}:e percentilen)";
        }

        $violent = $indicators->firstWhere('slug', 'crime_violent_rate');
        if ($violent) {
            $level = $violent['percentile'] >= 60 ? 'lägre än genomsnittet' : 'högre än genomsnittet';
            $parts[] = "våldsbrott {$level}";
        }

        return implode('. ', $parts).($parts ? '.' : '');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $indicators
     */
    private function economyDetail(Collection $indicators): string
    {
        $parts = [];

        $income = $indicators->firstWhere('slug', 'median_income');
        if ($income) {
            $formatted = number_format((int) $income['raw_value'], 0, ',', "\u{00A0}");
            $parts[] = "Medianinkomsten är {$formatted} kr";
        }

        $employment = $indicators->firstWhere('slug', 'employment_rate');
        if ($employment) {
            $parts[] = 'sysselsättningsgraden '.number_format((float) $employment['raw_value'], 1, ',', '')."\u{00A0}%";
        }

        return implode(', ', $parts).($parts ? '.' : '');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $indicators
     */
    private function educationDetail(Collection $indicators): string
    {
        $parts = [];

        $postSec = $indicators->firstWhere('slug', 'education_post_secondary_pct');
        if ($postSec) {
            $parts[] = number_format((float) $postSec['raw_value'], 1, ',', '')."\u{00A0}% har eftergymnasial utbildning";
        }

        $merit = $indicators->firstWhere('slug', 'school_merit_value_avg');
        if ($merit) {
            $parts[] = 'genomsnittligt meritvärde '.(int) $merit['raw_value'];
        }

        return implode('. ', $parts).($parts ? '.' : '');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $indicators
     */
    private function environmentDetail(Collection $indicators): string
    {
        $count = $indicators->count();
        $avgPctl = (int) round($indicators->avg('percentile'));

        return "Baserat på {$count} indikatorer med genomsnittlig percentil {$avgPctl}.";
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyVerdict(string $category): array
    {
        return [
            'label' => $category,
            'score' => null,
            'grade' => "\u{2014}",
            'color' => '#94a3b8',
            'verdict_sv' => 'Okänd kategori.',
            'trend_direction' => 'unknown',
            'indicator_count' => 0,
        ];
    }
}
