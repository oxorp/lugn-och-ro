<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Composite Trend Minimum Weight Coverage
    |--------------------------------------------------------------------------
    |
    | The minimum fraction of total active indicator weight that must have
    | trend data available before computing a composite trend. Set to 0.60
    | means 60% of the weight budget must have trends before we show a
    | composite trend arrow.
    |
    */
    'composite_trend_min_weight_coverage' => 0.60,

    /*
    |--------------------------------------------------------------------------
    | Stable Threshold (Percent)
    |--------------------------------------------------------------------------
    |
    | Percent change threshold below which a trend is considered "stable".
    | ±3% = stable by default.
    |
    */
    'stable_threshold_pct' => 3.0,

    /*
    |--------------------------------------------------------------------------
    | Minimum Confidence for Trend Display
    |--------------------------------------------------------------------------
    |
    | Minimum confidence score (0.0-1.0) required to display a trend direction.
    | Below this threshold, trends are hidden (treated as insufficient data).
    |
    */
    'min_trend_confidence' => 0.50,
];
