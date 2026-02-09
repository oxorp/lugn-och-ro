<?php

/**
 * Known reference points for indicator sanity checks.
 *
 * Each indicator slug maps to an array of checks:
 *   - 'kommun' + 'min' + 'max': average raw value for DeSOs in that kommun must be in range
 *   - 'national_median_min' + 'national_median_max': national median must be in range
 *
 * These values are based on well-known characteristics of Swedish municipalities
 * and should remain stable year-over-year. If a check fails, it's likely a data
 * ingestion bug rather than a genuine demographic shift.
 */

return [
    'education_post_secondary_pct' => [
        ['kommun' => 'Lund', 'min' => 40, 'max' => 85, 'label' => 'Lund post-secondary (university city)'],
        ['kommun' => 'Danderyd', 'min' => 50, 'max' => 85, 'label' => 'Danderyd post-secondary (wealthy suburb)'],
        ['kommun' => 'Lidingö', 'min' => 45, 'max' => 80, 'label' => 'Lidingö post-secondary'],
        ['national_median_min' => 30, 'national_median_max' => 65, 'label' => 'National median post-secondary'],
    ],
    'education_below_secondary_pct' => [
        ['kommun' => 'Lund', 'min' => 1, 'max' => 15, 'label' => 'Lund below-secondary (should be low)'],
        ['kommun' => 'Danderyd', 'min' => 1, 'max' => 10, 'label' => 'Danderyd below-secondary (should be low)'],
        ['national_median_min' => 5, 'national_median_max' => 25, 'label' => 'National median below-secondary'],
    ],
    'median_income' => [
        ['kommun' => 'Danderyd', 'min' => 350000, 'max' => 700000, 'label' => 'Danderyd income (wealthiest kommun)'],
        ['kommun' => 'Filipstad', 'min' => 150000, 'max' => 280000, 'label' => 'Filipstad income (low-income kommun)'],
        ['national_median_min' => 200000, 'national_median_max' => 350000, 'label' => 'National median income'],
    ],
    'employment_rate' => [
        ['national_median_min' => 60, 'national_median_max' => 90, 'label' => 'National median employment rate'],
    ],
    'low_economic_standard_pct' => [
        ['kommun' => 'Danderyd', 'min' => 1, 'max' => 15, 'label' => 'Danderyd low econ standard (should be low)'],
        ['national_median_min' => 5, 'national_median_max' => 25, 'label' => 'National median low econ standard'],
    ],
    'crime_violent_rate' => [
        ['national_median_min' => 100, 'national_median_max' => 4000, 'label' => 'National median violent crime rate'],
    ],
    'crime_property_rate' => [
        ['national_median_min' => 500, 'national_median_max' => 5000, 'label' => 'National median property crime rate'],
    ],
    'debt_rate_pct' => [
        ['kommun' => 'Lomma', 'min' => 0.3, 'max' => 3, 'label' => 'Lomma debt rate (lowest in Sweden)'],
        ['national_median_min' => 2, 'national_median_max' => 8, 'label' => 'National median debt rate'],
    ],
];
