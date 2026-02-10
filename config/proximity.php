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

    /*
    |--------------------------------------------------------------------------
    | Isochrone Configuration
    |--------------------------------------------------------------------------
    */

    'isochrone' => [
        'enabled' => env('ISOCHRONE_ENABLED', true),
        'valhalla_url' => env('VALHALLA_URL', 'http://valhalla:8002'),

        // Display contours shown on map (minutes), per urbanity tier
        'display_contours' => [
            'urban' => [5, 10, 15],
            'semi_urban' => [5, 10, 15],
            'rural' => [5, 10, 20],
        ],

        // Travel mode per urbanity tier
        'costing' => [
            'urban' => 'pedestrian',
            'semi_urban' => 'pedestrian',
            'rural' => 'auto',
        ],

        // Outermost contour used as scoring boundary per urbanity tier (minutes)
        'scoring_contour' => [
            'urban' => 15,
            'semi_urban' => 15,
            'rural' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Times (replaces scoring_radii when isochrone is enabled)
    |--------------------------------------------------------------------------
    |
    | Max travel time per category in minutes. Beyond this time,
    | the category contributes 0 to the proximity score.
    |
    */

    'scoring_times' => [
        'school' => [
            'urban' => 15,
            'semi_urban' => 15,
            'rural' => 20,
        ],
        'green_space' => [
            'urban' => 10,
            'semi_urban' => 10,
            'rural' => 5,
        ],
        'transit' => [
            'urban' => 8,
            'semi_urban' => 10,
            'rural' => 15,
        ],
        'grocery' => [
            'urban' => 10,
            'semi_urban' => 12,
            'rural' => 15,
        ],
        'negative_poi' => [
            'urban' => 5,
            'semi_urban' => 5,
            'rural' => 5,
        ],
        'positive_poi' => [
            'urban' => 10,
            'semi_urban' => 10,
            'rural' => 15,
        ],
    ],

];
