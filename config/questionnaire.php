<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Priority Options
    |--------------------------------------------------------------------------
    |
    | Available priorities that users can select in the questionnaire.
    | Each priority has a Swedish label, icon (FontAwesome), weight modifier,
    | and the categories/factors it affects in scoring.
    |
    | weight_modifier: Multiplier applied to related indicators (1.0 = no change)
    | affected_categories: Indicator categories in the composite score
    | affected_proximity_factors: POI-related proximity score factors
    |
    */

    'priorities' => [
        'schools' => [
            'label_sv' => 'Bra skolor',
            'icon' => 'graduation-cap',
            'weight_modifier' => 1.5,
            'affected_categories' => ['education'],
            'affected_proximity_factors' => ['school'],
        ],
        'safety' => [
            'label_sv' => 'Trygghet & säkerhet',
            'icon' => 'shield-halved',
            'weight_modifier' => 1.5,
            'affected_categories' => ['safety'],
            'affected_proximity_factors' => [],
        ],
        'green_areas' => [
            'label_sv' => 'Grönområden & natur',
            'icon' => 'tree',
            'weight_modifier' => 1.5,
            'affected_categories' => ['proximity'],
            'affected_proximity_factors' => ['green_space'],
        ],
        'shopping' => [
            'label_sv' => 'Butiker & service',
            'icon' => 'cart-shopping',
            'weight_modifier' => 1.5,
            'affected_categories' => [],
            'affected_proximity_factors' => ['grocery', 'positive_poi'],
        ],
        'transit' => [
            'label_sv' => 'Kollektivtrafik',
            'icon' => 'bus',
            'weight_modifier' => 1.5,
            'affected_categories' => [],
            'affected_proximity_factors' => ['transit'],
        ],
        'healthcare' => [
            'label_sv' => 'Sjukvård & vårdcentral',
            'icon' => 'heart-pulse',
            'weight_modifier' => 1.3,
            'affected_categories' => [],
            'affected_proximity_factors' => ['healthcare'],
        ],
        'dining' => [
            'label_sv' => 'Restauranger & matställen',
            'icon' => 'utensils',
            'weight_modifier' => 1.2,
            'affected_categories' => [],
            'affected_proximity_factors' => ['positive_poi'],
        ],
        'quiet' => [
            'label_sv' => 'Lugnt & fridfullt',
            'icon' => 'volume-off',
            'weight_modifier' => 1.3,
            'affected_categories' => ['safety'],
            'affected_proximity_factors' => ['negative_poi'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Priority Selections
    |--------------------------------------------------------------------------
    |
    | The maximum number of priorities a user can select.
    |
    */

    'max_priorities' => 3,

    /*
    |--------------------------------------------------------------------------
    | Walking Distance Options
    |--------------------------------------------------------------------------
    |
    | Available walking distance preferences in minutes.
    | Keys are the minute values, values are the Swedish display labels.
    |
    */

    'walking_distances' => [
        10 => '10 minuter',
        15 => '15 minuter',
        20 => '20 minuter',
        30 => '30 minuter',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Walking Distance
    |--------------------------------------------------------------------------
    |
    | The default walking distance preference in minutes if not selected.
    |
    */

    'default_walking_distance' => 15,

    /*
    |--------------------------------------------------------------------------
    | Ring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for reachability rings displayed on the report map.
    | Ring 1 is always 5 minutes walking. Rings 2 and 3 are dynamic based
    | on user preferences and urbanity tier.
    |
    */

    'ring_config' => [
        'ring_1' => [
            'minutes' => 5,
            'mode' => 'pedestrian',
            'label_sv' => 'Nåbart inom 5 min promenad',
            'color' => '#22c55e', // green-500
        ],
        'ring_2_defaults' => [
            'mode' => 'pedestrian',
            'label_template_sv' => 'Nåbart inom {minutes} min promenad',
            'color' => '#3b82f6', // blue-500
        ],
        'ring_3_defaults' => [
            'mode' => 'auto',
            'label_template_sv' => 'Nåbart inom {minutes} min bil',
            'color' => '#a855f7', // purple-500
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ring Rules by Urbanity Tier
    |--------------------------------------------------------------------------
    |
    | Defines how rings are generated based on urbanity tier and car ownership.
    | - urban/semi_urban: 2 rings (5 min walk + user preference walk)
    | - rural + car: 3 rings (5 min walk + user preference walk + 10 min drive)
    | - rural + no car: 3 rings (5 min walk + half preference + full preference)
    |
    */

    'ring_rules' => [
        'urban' => [
            'ring_count' => 2,
            'ring_2' => [
                'source' => 'user_preference',
                'mode' => 'pedestrian',
            ],
        ],
        'semi_urban' => [
            'ring_count' => 2,
            'ring_2' => [
                'source' => 'user_preference',
                'mode' => 'pedestrian',
            ],
        ],
        'rural' => [
            'with_car' => [
                'ring_count' => 3,
                'ring_2' => [
                    'source' => 'user_preference',
                    'mode' => 'pedestrian',
                ],
                'ring_3' => [
                    'minutes' => 10,
                    'mode' => 'auto',
                ],
            ],
            'without_car' => [
                'ring_count' => 3,
                'ring_2' => [
                    'source' => 'user_preference_half',
                    'mode' => 'pedestrian',
                ],
                'ring_3' => [
                    'source' => 'user_preference',
                    'mode' => 'pedestrian',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Question Labels (Swedish)
    |--------------------------------------------------------------------------
    |
    | UI labels for the questionnaire questions.
    |
    */

    'labels' => [
        'question_1_title' => 'Vad är viktigast för dig?',
        'question_1_subtitle' => 'Välj upp till 3 prioriteringar',
        'question_2_title' => 'Hur långt är du bekväm med att promenera?',
        'question_2_subtitle' => 'Detta påverkar hur vi visar närliggande platser',
        'question_3_title' => 'Har du tillgång till bil?',
        'question_3_subtitle' => 'Detta hjälper oss visa relevanta avstånd för landsbygden',
        'yes' => 'Ja',
        'no' => 'Nej',
    ],

];
