<?php

namespace Database\Seeders;

use App\Models\Indicator;
use App\Models\ValidationRule;
use Illuminate\Database\Seeder;

class ValidationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = $this->indicatorRules();

        foreach ($rules as $ruleData) {
            $indicatorId = null;
            if (isset($ruleData['indicator_slug'])) {
                $indicatorId = Indicator::query()->where('slug', $ruleData['indicator_slug'])->value('id');
                if (! $indicatorId) {
                    continue;
                }
            }

            ValidationRule::query()->updateOrCreate(
                [
                    'indicator_id' => $indicatorId,
                    'source' => $ruleData['source'] ?? null,
                    'rule_type' => $ruleData['rule_type'],
                    'name' => $ruleData['name'],
                ],
                [
                    'severity' => $ruleData['severity'],
                    'parameters' => $ruleData['parameters'],
                    'is_active' => true,
                    'blocks_scoring' => $ruleData['blocks_scoring'] ?? false,
                    'description' => $ruleData['description'] ?? null,
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function indicatorRules(): array
    {
        return [
            // === median_income ===
            [
                'indicator_slug' => 'median_income',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Median income must be positive and below 2M SEK',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 2000000],
            ],
            [
                'indicator_slug' => 'median_income',
                'source' => 'scb',
                'rule_type' => 'completeness',
                'name' => 'Median income coverage at least 85%',
                'severity' => 'warning',
                'parameters' => ['min_coverage_pct' => 85],
            ],
            [
                'indicator_slug' => 'median_income',
                'source' => 'scb',
                'rule_type' => 'change_rate',
                'name' => 'Median income max 40% year-over-year change',
                'severity' => 'warning',
                'parameters' => ['max_change_pct' => 40],
            ],
            [
                'indicator_slug' => 'median_income',
                'source' => 'scb',
                'rule_type' => 'distribution',
                'name' => 'Median income national distribution check',
                'severity' => 'warning',
                'parameters' => [
                    'expected_mean_min' => 180000,
                    'expected_mean_max' => 350000,
                    'expected_stddev_min' => 30000,
                    'expected_stddev_max' => 150000,
                ],
            ],

            // === employment_rate ===
            [
                'indicator_slug' => 'employment_rate',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Employment rate must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],
            [
                'indicator_slug' => 'employment_rate',
                'source' => 'scb',
                'rule_type' => 'completeness',
                'name' => 'Employment rate coverage at least 85%',
                'severity' => 'warning',
                'parameters' => ['min_coverage_pct' => 85],
            ],

            // === low_economic_standard_pct ===
            [
                'indicator_slug' => 'low_economic_standard_pct',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Low economic standard must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],
            [
                'indicator_slug' => 'low_economic_standard_pct',
                'source' => 'scb',
                'rule_type' => 'completeness',
                'name' => 'Low economic standard coverage at least 85%',
                'severity' => 'warning',
                'parameters' => ['min_coverage_pct' => 85],
            ],

            // === education_post_secondary_pct ===
            [
                'indicator_slug' => 'education_post_secondary_pct',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Education post-secondary must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],
            [
                'indicator_slug' => 'education_post_secondary_pct',
                'source' => 'scb',
                'rule_type' => 'completeness',
                'name' => 'Education post-secondary coverage at least 85%',
                'severity' => 'warning',
                'parameters' => ['min_coverage_pct' => 85],
            ],

            // === population ===
            [
                'indicator_slug' => 'population',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Population must be 1-50000',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 1, 'max' => 50000],
            ],

            // === mean_age ===
            [
                'indicator_slug' => 'mean_age',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Mean age must be 15-80',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 15, 'max' => 80],
            ],

            // === foreign_background_pct ===
            [
                'indicator_slug' => 'foreign_background_pct',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Foreign background must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],

            // === children_pct ===
            [
                'indicator_slug' => 'children_pct',
                'source' => 'scb',
                'rule_type' => 'range',
                'name' => 'Children percentage must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],

            // === school_merit_value_avg ===
            [
                'indicator_slug' => 'school_merit_value_avg',
                'source' => 'skolverket',
                'rule_type' => 'range',
                'name' => 'School merit value must be 50-340',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 50, 'max' => 340],
            ],
            [
                'indicator_slug' => 'school_merit_value_avg',
                'source' => 'skolverket',
                'rule_type' => 'completeness',
                'name' => 'School merit value coverage (many DeSOs lack schools)',
                'severity' => 'info',
                'parameters' => ['min_coverage_pct' => 15],
            ],

            // === school_teacher_certification_avg ===
            [
                'indicator_slug' => 'school_teacher_certification_avg',
                'source' => 'skolverket',
                'rule_type' => 'range',
                'name' => 'Teacher certification must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],

            // === school_goal_achievement_avg ===
            [
                'indicator_slug' => 'school_goal_achievement_avg',
                'source' => 'skolverket',
                'rule_type' => 'range',
                'name' => 'School goal achievement must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],

            // === crime indicators ===
            [
                'indicator_slug' => 'crime_violent_rate',
                'source' => 'bra',
                'rule_type' => 'range',
                'name' => 'Violent crime rate must be non-negative',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 50000],
            ],
            [
                'indicator_slug' => 'crime_property_rate',
                'source' => 'bra',
                'rule_type' => 'range',
                'name' => 'Property crime rate must be non-negative',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100000],
            ],
            [
                'indicator_slug' => 'crime_total_rate',
                'source' => 'bra',
                'rule_type' => 'range',
                'name' => 'Total crime rate must be non-negative',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 200000],
            ],
            [
                'indicator_slug' => 'perceived_safety',
                'source' => 'bra',
                'rule_type' => 'range',
                'name' => 'Perceived safety must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],

            // === kronofogden indicators ===
            [
                'indicator_slug' => 'debt_rate_pct',
                'source' => 'kronofogden',
                'rule_type' => 'range',
                'name' => 'Debt rate must be 0-100%',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 100],
            ],
            [
                'indicator_slug' => 'eviction_rate',
                'source' => 'kronofogden',
                'rule_type' => 'range',
                'name' => 'Eviction rate must be non-negative',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 1000],
            ],
            [
                'indicator_slug' => 'median_debt_sek',
                'source' => 'kronofogden',
                'rule_type' => 'range',
                'name' => 'Median debt must be non-negative',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min' => 0, 'max' => 5000000],
            ],

            // === GLOBAL RULES ===
            [
                'source' => 'scb',
                'rule_type' => 'global_min_count',
                'name' => 'SCB ingestion must have at least 1000 DeSOs',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min_deso_count' => 1000],
            ],
            [
                'source' => 'scb',
                'rule_type' => 'global_no_identical',
                'name' => 'SCB indicators must not have all-identical values',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => [],
            ],
            [
                'source' => 'scb',
                'rule_type' => 'global_null_spike',
                'name' => 'SCB indicators must not have unexpected NULL spikes',
                'severity' => 'warning',
                'parameters' => ['max_null_increase_pct' => 15],
            ],
            [
                'source' => 'skolverket',
                'rule_type' => 'global_no_identical',
                'name' => 'Skolverket indicators must not have all-identical values',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => [],
            ],
            [
                'source' => 'bra',
                'rule_type' => 'global_min_count',
                'name' => 'BRA ingestion must have at least 1000 DeSOs',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min_deso_count' => 1000],
            ],
            [
                'source' => 'kronofogden',
                'rule_type' => 'global_min_count',
                'name' => 'Kronofogden ingestion must have at least 1000 DeSOs',
                'severity' => 'error',
                'blocks_scoring' => true,
                'parameters' => ['min_deso_count' => 1000],
            ],
        ];
    }
}
