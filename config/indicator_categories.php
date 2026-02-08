<?php

return [
    'safety' => [
        'label' => 'Trygghet & brottslighet',
        'label_short' => 'Trygghet',
        'icon' => 'shield',
        'emoji' => "\u{1F6E1}\u{FE0F}",
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
        'icon' => 'bar-chart-3',
        'emoji' => "\u{1F4CA}",
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
        'emoji' => "\u{1F393}",
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
        'icon' => 'map-pin',
        'emoji' => "\u{1F4CD}",
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
