<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Score Color Scale
    |--------------------------------------------------------------------------
    |
    | Continuous gradient stops for the 0-100 composite score.
    | Used by: heatmap tiles, sidebar score badge, indicator bars,
    | school markers, legend.
    |
    | Format: score threshold => hex color
    | The frontend interpolates between these stops.
    |
    */

    'gradient_stops' => [
        0 => '#c0392b',
        25 => '#e74c3c',
        40 => '#f39c12',
        50 => '#f1c40f',
        60 => '#f1c40f',
        75 => '#27ae60',
        100 => '#1a7a2e',
    ],

    /*
    |--------------------------------------------------------------------------
    | Score Labels & Thresholds
    |--------------------------------------------------------------------------
    |
    | Human-readable labels for score ranges.
    | Swedish labels are the primary display language.
    |
    */

    'labels' => [
        ['min' => 80, 'max' => 100, 'label_sv' => 'Starkt tillväxtområde', 'label_en' => 'Strong Growth Area', 'color' => '#1a7a2e'],
        ['min' => 60, 'max' => 79, 'label_sv' => 'Stabil / positiv utsikt', 'label_en' => 'Stable / Positive Outlook', 'color' => '#27ae60'],
        ['min' => 40, 'max' => 59, 'label_sv' => 'Blandade signaler', 'label_en' => 'Mixed Signals', 'color' => '#f1c40f'],
        ['min' => 20, 'max' => 39, 'label_sv' => 'Förhöjd risk', 'label_en' => 'Elevated Risk', 'color' => '#e74c3c'],
        ['min' => 0, 'max' => 19, 'label_sv' => 'Hög risk / vikande', 'label_en' => 'High Risk / Declining', 'color' => '#c0392b'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Special Colors
    |--------------------------------------------------------------------------
    */

    'no_data' => '#d5d5d5',
    'no_data_border' => '#bbbbbb',
    'selected_border' => '#1e3a5f',

    /*
    |--------------------------------------------------------------------------
    | School Marker Colors
    |--------------------------------------------------------------------------
    |
    | School markers on the map are colored by meritvärde.
    | Same red-green logic but with different thresholds.
    |
    */

    'school_markers' => [
        'high' => '#27ae60',
        'medium' => '#f1c40f',
        'low' => '#e74c3c',
        'no_data' => '#999999',
    ],

    /*
    |--------------------------------------------------------------------------
    | Indicator Bar Colors
    |--------------------------------------------------------------------------
    |
    | Used in sidebar indicator bars and report pages.
    | "good" = this indicator contributes positively to the score.
    | "bad" = this indicator pulls the score down.
    |
    */

    'indicator_bar' => [
        'good' => '#27ae60',
        'bad' => '#e74c3c',
        'neutral' => '#94a3b8',
    ],
];
