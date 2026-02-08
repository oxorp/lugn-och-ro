# Proximity Indicators

> Real-time per-address scoring based on distance to nearby amenities, schools, and negative POIs.

## Overview

Proximity indicators are fundamentally different from area-level indicators:

| Property | Area Indicators | Proximity Indicators |
|---|---|---|
| Scope | Per DeSO (6,160 areas) | Per coordinate (infinite) |
| Computation | Batch (pipeline) | Real-time (< 200ms) |
| Storage | `indicator_values` table | Not stored |
| Normalization | Percentile rank | None (already 0–100) |
| Contribution | 70% of blended score | 30% of blended score |

Two addresses in the same DeSO can have different proximity scores based on what's walkable from each location.

## Service

**File**: `app/Services/ProximityScoreService.php`

The service computes 6 proximity factors for any `(lat, lng)` coordinate using PostGIS spatial queries.

## Factors

### `prox_school` — School Proximity & Quality

**Weight**: 0.10 | **Radius**: 2 km | **Direction**: positive

Finds up to 5 grundskolor within 2 km and scores the best school by quality × distance decay.

**Scoring**:
```
quality = (merit_value - 150) / 130   # normalized 0-1
decay = 1 - (distance_m / 2000)       # linear decay
score = quality × decay × 100         # 0-100
```

- If no schools have merit data, half credit (50 × decay) is given for proximity alone
- Only grundskolor are considered (ILIKE `'%grundskola%'`)

**Details returned**: nearest school name, merit value, distance, count within 2 km

### `prox_green_space` — Green Space Access

**Weight**: 0.04 | **Radius**: 1 km | **Direction**: positive

Distance to nearest park or nature reserve.

**Scoring**:
```
decay = 1 - (distance_m / 1000)
score = decay × 100
```

**Source**: POIs with category `park` or `nature_reserve`

### `prox_transit` — Transit Access

**Weight**: 0.05 | **Radius**: 1 km | **Direction**: positive

Nearest transit stop with mode weighting and count bonus.

**Scoring**:
```
# Mode multiplier
rail/station: 1.5×
tram_stop: 1.2×
bus/default: 1.0×

# Best stop score
base = (1 - distance_m / 1000) × mode_weight

# Count bonus (max 20%)
bonus = min(0.20, stop_count × 0.02)

score = min(100, (base + bonus) × 100)
```

**Details returned**: nearest stop name, type, distance, count within 1 km

### `prox_grocery` — Grocery Access

**Weight**: 0.03 | **Radius**: 1 km | **Direction**: positive

Distance to nearest grocery store.

**Scoring**: Same linear decay as green space.

**Details returned**: nearest store name, distance

### `prox_negative_poi` — Negative POI Proximity

**Weight**: 0.04 | **Radius**: 500 m | **Direction**: negative

Penalty from nearby negative-signal POIs (gambling, pawn shops, etc.).

**Scoring**:
```
# Starts at 100 (no negatives nearby = perfect score)
# Each negative POI subtracts up to 20 points, distance-weighted
penalty_per_poi = (1 - distance_m / 500) × 20
score = max(0, 100 - Σ penalties)
```

**Note**: Uses a shorter 500 m radius since negative POIs only matter if very close.

**Details returned**: count, nearest name and distance

### `prox_positive_poi` — Positive POI Density

**Weight**: 0.04 | **Radius**: 1 km | **Direction**: positive

Bonus from nearby positive-signal POIs (restaurants, fitness, cafes, etc.), excluding categories already scored separately (grocery, transit, parks).

**Scoring**:
```
# Diminishing returns per POI
bonus_per_poi = (1 - distance_m / 1000) × 15 × (1 / (rank + 1))
score = min(100, Σ bonuses)
```

Up to 20 positive POIs are considered.

**Details returned**: count, category types present

## Weight Management

Proximity weights are stored in the `indicators` table (category = `proximity`) so they can be adjusted via the admin dashboard alongside area-level weights.

The `ProximityResult` DTO caches weights for 5 minutes:

```php
$dbWeights = Cache::remember('proximity_indicator_weights', 300, fn () =>
    Indicator::where('category', 'proximity')
        ->where('is_active', true)
        ->pluck('weight', 'slug')
        ->toArray()
);
```

Defaults are used if no DB weights exist.

## Seeder

**File**: `database/seeders/ProximityIndicatorSeeder.php`

Creates the 6 proximity indicators and rebalances area-level weights:

- Area weights scaled by `0.753` (= 0.70 / 0.93) so they sum to ~70%
- Proximity weights sum to 30%
- Total = 100%

## DTOs

**`ProximityFactor`** (`app/DataTransferObjects/ProximityFactor.php`):
- `slug`: Factor identifier
- `score`: 0–100 integer (null if no data)
- `details`: Associative array with factor-specific metadata

**`ProximityResult`** (`app/DataTransferObjects/ProximityResult.php`):
- Contains all 6 `ProximityFactor` instances
- `compositeScore()`: Weighted average of all factors
- `toArray()`: Serializes for API response

## Related

- [Scoring Engine](/architecture/scoring-engine)
- [Indicator Pattern](/architecture/indicator-pattern)
- [Location Lookup API](/api/location-lookup)
- [Master Reference](/indicators/)
