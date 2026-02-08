<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display Radius
    |--------------------------------------------------------------------------
    |
    | The visual circle drawn on the map when a pin is dropped, in meters.
    | Should match or exceed the largest query radius so the circle
    | encompasses all returned data.
    |
    */

    'display_radius' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Query Radii (LocationController)
    |--------------------------------------------------------------------------
    |
    | How far the location API queries for schools and POIs to show in the
    | sidebar. These are display queries, not scoring queries.
    |
    */

    'school_query_radius' => 2000,
    'poi_query_radius' => 1500,

    /*
    |--------------------------------------------------------------------------
    | Scoring Radii (ProximityScoreService)
    |--------------------------------------------------------------------------
    |
    | Max distance per category used for proximity score calculation.
    | Beyond this distance, the category contributes 0 to the score.
    |
    */

    'scoring_radii' => [
        'school' => 2000,
        'green_space' => 1500,
        'transit' => 1000,
        'grocery' => 1000,
        'negative_poi' => 500,
        'positive_poi' => 1000,
    ],

];
