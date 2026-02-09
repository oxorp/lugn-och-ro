<?php

return [
    'sources' => [
        'scb' => [
            'name' => 'SCB Demographics',
            'description' => 'Population, income, employment, education statistics at DeSO level',
            'commands' => [
                'ingest' => ['command' => 'ingest:scb', 'options' => ['--all' => true]],
                'normalize' => ['command' => 'normalize:indicators', 'options' => []],
            ],
            'expected_frequency' => 'annually',
            'stale_after_days' => 400,
            'critical' => true,
            'indicators' => [
                'median_income',
                'low_economic_standard_pct',
                'employment_rate',
                'education_post_secondary_pct',
                'education_below_secondary_pct',
                'foreign_background_pct',
                'population',
                'rental_tenure_pct',
            ],
        ],
        'skolverket_schools' => [
            'name' => 'Skolverket School Registry',
            'description' => 'School locations, types, operators from Skolenhetsregistret',
            'commands' => [
                'ingest' => ['command' => 'ingest:skolverket-schools', 'options' => []],
            ],
            'expected_frequency' => 'monthly',
            'stale_after_days' => 45,
            'critical' => true,
            'indicators' => [],
        ],
        'skolverket_stats' => [
            'name' => 'Skolverket Statistics',
            'description' => 'School performance: meritvärde, goal achievement, teacher certification',
            'commands' => [
                'ingest' => ['command' => 'ingest:skolverket-stats', 'options' => []],
                'aggregate' => ['command' => 'aggregate:school-indicators', 'options' => ['--academic-year' => '2020/21']],
            ],
            'expected_frequency' => 'annually',
            'stale_after_days' => 400,
            'critical' => true,
            'indicators' => [
                'school_merit_value_avg',
                'school_goal_achievement_avg',
                'school_teacher_certification_avg',
            ],
        ],
        'bra' => [
            'name' => 'BRÅ Crime Statistics',
            'description' => 'Crime rates by category from BRÅ, disaggregated to DeSO level',
            'commands' => [
                'ingest' => ['command' => 'ingest:bra-crime', 'options' => []],
                'disaggregate' => ['command' => 'disaggregate:crime', 'options' => []],
            ],
            'expected_frequency' => 'annually',
            'stale_after_days' => 400,
            'critical' => true,
            'indicators' => [
                'crime_violent_rate',
                'crime_property_rate',
                'crime_total_rate',
            ],
        ],
        'vulnerability' => [
            'name' => 'Vulnerability Areas & NTU',
            'description' => 'Police vulnerability area classifications and NTU perceived safety survey',
            'commands' => [
                'ingest_areas' => ['command' => 'ingest:vulnerability-areas', 'options' => []],
                'ingest_ntu' => ['command' => 'ingest:ntu', 'options' => []],
            ],
            'expected_frequency' => 'annually',
            'stale_after_days' => 400,
            'critical' => false,
            'indicators' => [
                'perceived_safety',
                'vulnerability_flag',
            ],
        ],
        'kronofogden' => [
            'name' => 'Kronofogden Financial Distress',
            'description' => 'Debt rates, evictions, median debt from Kolada API',
            'commands' => [
                'ingest' => ['command' => 'ingest:kronofogden', 'options' => ['--source' => 'kolada']],
                'disaggregate' => ['command' => 'disaggregate:kronofogden', 'options' => []],
                'aggregate' => ['command' => 'aggregate:kronofogden-indicators', 'options' => []],
            ],
            'expected_frequency' => 'annually',
            'stale_after_days' => 400,
            'critical' => false,
            'indicators' => [
                'debt_rate_pct',
                'eviction_rate',
                'median_debt_sek',
            ],
        ],
        'pois' => [
            'name' => 'Points of Interest',
            'description' => 'Amenities, transit, services from OpenStreetMap',
            'commands' => [
                'ingest' => ['command' => 'ingest:pois', 'options' => ['--source' => 'osm', '--all' => true]],
                'assign' => ['command' => 'assign:poi-deso', 'options' => []],
                'aggregate' => ['command' => 'aggregate:poi-indicators', 'options' => []],
            ],
            'expected_frequency' => 'monthly',
            'stale_after_days' => 60,
            'critical' => false,
            'indicators' => [
                'grocery_density',
                'healthcare_density',
                'restaurant_density',
                'fitness_density',
                'transit_density',
                'gambling_density',
                'pawn_shop_density',
                'fast_food_density',
            ],
        ],
        'scoring' => [
            'name' => 'Score Computation',
            'description' => 'Normalize indicators and compute composite scores',
            'commands' => [
                'normalize' => ['command' => 'normalize:indicators', 'options' => []],
                'score' => ['command' => 'compute:scores', 'options' => []],
                'trends' => ['command' => 'compute:trends', 'options' => []],
            ],
            'expected_frequency' => 'on_demand',
            'stale_after_days' => null,
            'critical' => true,
            'indicators' => [],
        ],
    ],

    // Maps ingestion_log.source values to pipeline config keys
    // for sources that don't match 1:1
    'source_mapping' => [
        'scb_wfs' => 'scb',
        'skolverket' => 'skolverket_schools',
        'polisen' => 'vulnerability',
        'bra_ntu' => 'vulnerability',
        'bra_disaggregated' => 'bra',
        'kronofogden_disaggregated' => 'kronofogden',
        'kronofogden_indicators' => 'kronofogden',
        'poi_osm' => 'pois',
    ],

    'pipeline_order' => [
        'scb',
        'skolverket_schools',
        'skolverket_stats',
        'bra',
        'vulnerability',
        'kronofogden',
        'pois',
        'scoring',
    ],
];
