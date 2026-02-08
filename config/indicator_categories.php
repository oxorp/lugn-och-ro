<?php

return [
    'safety' => [
        'label' => 'Trygghet & brottslighet',
        'label_short' => 'Trygghet',
        'icon' => 'shield-halved',
        'bar_count' => 3,
        'indicators' => [
            'crime_violent_rate',
            'crime_property_rate',
            'crime_total_rate',
            'vulnerability_flag',
            'perceived_safety',
        ],
    ],
    'economy' => [
        'label' => 'Ekonomi & arbetsmarknad',
        'label_short' => 'Ekonomi',
        'icon' => 'chart-column',
        'bar_count' => 3,
        'indicators' => [
            'median_income',
            'low_economic_standard_pct',
            'employment_rate',
            'debt_rate_pct',
            'eviction_rate',
            'median_debt_sek',
        ],
    ],
    'education' => [
        'label' => 'Utbildning',
        'label_short' => 'Utbildning',
        'icon' => 'graduation-cap',
        'bar_count' => 2,
        'indicators' => [
            'education_post_secondary_pct',
            'education_below_secondary_pct',
            'school_merit_value_avg',
            'school_goal_achievement_avg',
            'school_teacher_certification_avg',
        ],
    ],
    'proximity' => [
        'label' => 'Närhetsanalys',
        'label_short' => 'Närhet',
        'icon' => 'location-dot',
        'bar_count' => 3,
        'indicators' => [
            'prox_school',
            'prox_green_space',
            'prox_transit',
            'prox_grocery',
            'prox_positive_poi',
            'prox_negative_poi',
        ],
    ],
];
