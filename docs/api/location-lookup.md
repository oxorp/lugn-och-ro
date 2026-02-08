# Location Lookup

> Per-address scoring — the primary API for getting blended neighborhood + proximity scores.

## Endpoint

```
GET /api/location/{lat},{lng}
```

**Controller**: `app/Http/Controllers/LocationController.php`
**Route name**: `location.show`

## Parameters

| Parameter | Type | Description |
|---|---|---|
| `lat` | float | Latitude (WGS84) |
| `lng` | float | Longitude (WGS84) |

Example: `GET /api/location/59.3293,18.0686`

## How It Works

1. **PostGIS point-in-polygon** — Find which DeSO contains this coordinate
2. **Area score** — Load latest `composite_scores` for the DeSO
3. **Proximity score** — `ProximityScoreService` computes 6 distance-based factors in real-time
4. **Blend** — `area_score × 0.70 + proximity_score × 0.30`
5. **Nearby data** — Schools and POIs within urbanity-tiered radii (PostGIS `ST_DWithin`)
6. **Tier gating** — Public tier gets score only; paid tiers get full breakdown

## Response Format

### Public Tier (tier = 0)

Returns location and score only, no detailed breakdowns:

```json
{
  "location": {
    "lat": 59.3293,
    "lng": 18.0686,
    "deso_code": "0180A0010",
    "kommun": "Stockholm",
    "lan_code": "01",
    "area_km2": 1.23,
    "urbanity_tier": "urban"
  },
  "score": {
    "value": 72.3,
    "area_score": 75.0,
    "proximity_score": 66.1,
    "trend_1y": 1.2,
    "label": "Stabilt / Positivt",
    "top_positive": ["median_income", "school_merit_value_avg"],
    "top_negative": ["crime_violent_rate"],
    "factor_scores": { "income": 0.82, "crime": 0.45 }
  },
  "tier": 0,
  "display_radius": 1500,
  "proximity": null,
  "indicators": [],
  "schools": [],
  "pois": [],
  "poi_categories": []
}
```

### Paid Tiers (tier >= 1)

Full response with proximity breakdown, indicators, schools, and POIs:

```json
{
  "location": { ... },
  "score": { ... },
  "tier": 3,
  "display_radius": 1500,
  "proximity": {
    "composite": 66.1,
    "safety_score": 0.82,
    "safety_zone": { "level": "high", "label": "Hög" },
    "urbanity_tier": "urban",
    "factors": [
      {
        "slug": "prox_school",
        "score": 78,
        "details": {
          "nearest_school": "Stockholms grundskola",
          "nearest_merit": 245.0,
          "nearest_distance_m": 320,
          "effective_distance_m": 378,
          "schools_within_2km": 4
        }
      },
      {
        "slug": "prox_green_space",
        "score": 92,
        "details": {
          "nearest_park": "Humlegården",
          "distance_m": 80,
          "effective_distance_m": 94
        }
      }
    ]
  },
  "indicators": [
    {
      "slug": "median_income",
      "name": "Median Disposable Income",
      "raw_value": 312000,
      "normalized_value": 0.82,
      "unit": "SEK",
      "direction": "positive",
      "category": "income",
      "normalization_scope": "national"
    }
  ],
  "schools": [
    {
      "name": "Stockholms grundskola",
      "type": "Grundskola",
      "operator_type": "KOMMUN",
      "distance_m": 320,
      "merit_value": 245.0,
      "goal_achievement": 82.5,
      "teacher_certification": 91.2,
      "student_count": 450,
      "lat": 59.330,
      "lng": 18.070
    }
  ],
  "pois": [
    {
      "name": "ICA Nära",
      "category": "grocery",
      "lat": 59.329,
      "lng": 18.069,
      "distance_m": 150
    }
  ],
  "poi_categories": {
    "grocery": { "name": "Grocery", "color": "#22c55e", "icon": "shopping-cart", "signal": "positive" }
  }
}
```

## Score Blending

```php
// LocationController.php
const AREA_WEIGHT = 0.70;
const PROXIMITY_WEIGHT = 0.30;

$blendedScore = $areaScore * 0.70 + $proximityScore * 0.30;
```

If no area score exists (rare), a default of 50 is used.

## Safety Modulation

The proximity response includes safety context:

- `safety_score` — 0.0 (worst) to 1.0 (safest), from `SafetyScoreService`
- `safety_zone` — `{ level: "high"|"medium"|"low", label: "Hög"|"Medel"|"Låg" }`
- Factor details include `effective_distance_m` — physical distance inflated by safety risk

In unsafe areas, effective distances are larger than physical distances, reducing proximity scores for safety-sensitive categories (parks, restaurants, nightlife). Necessities (grocery, healthcare) are less affected.

## Proximity Factors

All scoring radii adapt to the DeSO's urbanity tier (urban / semi-urban / rural):

| Factor | Urban | Semi-Urban | Rural | Key Data |
|---|---|---|---|---|
| `prox_school` | 1,500 m | 2,000 m | 3,500 m | School name, merit value, distance, effective distance, count |
| `prox_green_space` | 1,000 m | 1,500 m | 2,500 m | Park name, distance, effective distance |
| `prox_transit` | 800 m | 1,200 m | 2,500 m | Stop name, type, distance, effective distance, count |
| `prox_grocery` | 800 m | 1,200 m | 2,000 m | Store name, distance, effective distance |
| `prox_negative_poi` | 400 m | 500 m | 500 m | Count of negative POIs, nearest name and distance |
| `prox_positive_poi` | 800 m | 1,000 m | 1,500 m | Count and category types |

## Search & Display Radii

The API also uses urbanity-tiered radii for nearby data queries and the map circle:

| Data | Urban | Semi-Urban | Rural |
|---|---|---|---|
| Schools query | 1,500 m | 2,000 m | 3,500 m |
| POIs query | 1,000 m | 1,500 m | 2,500 m |
| Display radius (map circle) | 1,500 m | 2,000 m | 3,500 m |

The `display_radius` field in the response provides the appropriate radius in meters for drawing the map circle.

## Error Responses

| Status | Condition |
|---|---|
| 404 | `{"error": "Location outside Sweden"}` — coordinate doesn't fall in any DeSO polygon |

## Performance

The proximity score computation targets **< 200ms** per request. All PostGIS queries use spatial indexes on `geom` columns with `ST_DWithin` for efficient radius search.

## Related

- [Scoring Engine](/architecture/scoring-engine)
- [Proximity Indicators](/indicators/proximity)
- [DeSO Scores](/api/deso-scores)
