<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display Radius
    |--------------------------------------------------------------------------
    |
    | The visual circle drawn on the map when a pin is dropped, in meters.
    | Per-urbanity-tier values so the circle adapts to context.
    |
    */

    'display_radius' => [
        'urban' => 1500,
        'semi_urban' => 2000,
        'rural' => 3500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Radii (LocationController)
    |--------------------------------------------------------------------------
    |
    | How far the location API queries for schools and POIs to show in the
    | sidebar. These are display queries, not scoring queries.
    |
    */

    'school_query_radius' => [
        'urban' => 1500,
        'semi_urban' => 2000,
        'rural' => 3500,
    ],

    'poi_query_radius' => [
        'urban' => 1000,
        'semi_urban' => 1500,
        'rural' => 2500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Radii (ProximityScoreService)
    |--------------------------------------------------------------------------
    |
    | Max distance per category used for proximity score calculation.
    | Beyond this distance, the category contributes 0 to the score.
    | Per-urbanity-tier: urban is strict, rural is forgiving.
    |
    */

    'scoring_radii' => [
        'school' => [
            'urban' => 1500,
            'semi_urban' => 2000,
            'rural' => 3500,
        ],
        'green_space' => [
            'urban' => 1000,
            'semi_urban' => 1500,
            'rural' => 2500,
        ],
        'transit' => [
            'urban' => 800,
            'semi_urban' => 1200,
            'rural' => 2500,
        ],
        'grocery' => [
            'urban' => 800,
            'semi_urban' => 1200,
            'rural' => 2000,
        ],
        'negative_poi' => [
            'urban' => 400,
            'semi_urban' => 500,
            'rural' => 500,
        ],
        'positive_poi' => [
            'urban' => 800,
            'semi_urban' => 1000,
            'rural' => 1500,
        ],
    ],

];
