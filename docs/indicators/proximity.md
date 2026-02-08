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

## Services

**`ProximityScoreService`** (`app/Services/ProximityScoreService.php`) — Computes 6 proximity factors for any `(lat, lng)` coordinate using PostGIS spatial queries, with safety-modulated distance decay.

**`SafetyScoreService`** (`app/Services/SafetyScoreService.php`) — Computes a 0.0–1.0 safety score per DeSO from crime/safety indicators and socioeconomic proxies. Used to modulate proximity distances.

## Safety-Modulated Distance Decay

Physical distance alone doesn't capture walkability. A park 500m away in a high-crime area provides less real value than the same park in a safe neighborhood. The proximity system handles this through **safety modulation**.

### How It Works

1. `SafetyScoreService` computes a 0.0 (worst) to 1.0 (safest) score for the pin's DeSO
2. Each POI category has a `safety_sensitivity` value (0.0–1.5) controlling how much safety affects its score
3. Physical distance is inflated into an "effective distance" before decay:

```
risk_penalty = (1.0 - safety_score) × safety_sensitivity
effective_distance = physical_distance × (1.0 + risk_penalty)
decay = max(0, 1 - effective_distance / max_distance)
```

### Example

A park 500m away with `safety_sensitivity = 1.0`:
- **Safe area** (safety = 0.90): effective = 500 × 1.10 = **550m** → decay 0.45
- **Unsafe area** (safety = 0.15): effective = 500 × 1.85 = **925m** → decay 0.08

### Safety Sensitivity by Category

| Sensitivity | Categories | Rationale |
|---|---|---|
| 0.0 | All negative-signal POIs | Badness doesn't increase with area risk |
| 0.3 | Grocery, pharmacy, healthcare | Necessities — people go regardless |
| 0.5 | Public transport | Fixed schedule, other passengers around |
| 0.8 | Schools, libraries, swimming | Medium — kids walk there daily |
| 1.0 | Parks, fitness, nature reserves | Standard discretionary outdoor |
| 1.2 | Restaurants | Discretionary, often evening visits |
| 1.5 | Cultural venues, nightclubs | Highest — nightlife is most safety-sensitive |

### Safety Score Signals

The `SafetyScoreService` uses 7 indicators with weight redistribution:

| Indicator | Weight | Inverted | Type |
|---|---|---|---|
| `crime_violent_rate` | 0.25 | Yes | Direct crime |
| `perceived_safety` | 0.20 | No | Direct safety |
| `vulnerability_flag` | 0.20 | Yes | Direct crime |
| `crime_total_rate` | 0.10 | Yes | Direct crime |
| `employment_rate` | 0.10 | No | Socioeconomic proxy |
| `low_economic_standard_pct` | 0.10 | Yes | Socioeconomic proxy |
| `education_below_secondary_pct` | 0.05 | Yes | Socioeconomic proxy |

Direct crime/safety signals account for 75%, socioeconomic proxies 25%. Cached for 10 minutes per DeSO.

### Safety Zones

The API response includes a human-readable safety zone:

| Safety Score | Level | Label |
|---|---|---|
| > 0.65 | `high` | Hög |
| 0.35–0.65 | `medium` | Medel |
| < 0.35 | `low` | Låg |

## Factors

### `prox_school` — School Proximity & Quality

**Weight**: 0.10 | **Radius**: 2 km | **Direction**: positive

Finds up to 5 grundskolor within 2 km and scores the best school by quality × safety-modulated distance decay.

**Scoring**:
```
quality = (merit_value - 150) / 130              # normalized 0-1
decay = decayWithSafety(distance, 2000, safety, 0.80)  # safety-modulated
score = quality × decay × 100                    # 0-100
```

- If no schools have merit data, half credit (50 × decay) is given for proximity alone
- Only grundskolor are considered (ILIKE `'%grundskola%'`)
- Safety sensitivity: 0.80 (from `school_grundskola` category)

**Details returned**: nearest school name, merit value, distance, effective distance, count within 2 km

### `prox_green_space` — Green Space Access

**Weight**: 0.04 | **Radius**: 1 km | **Direction**: positive

Distance to nearest park or nature reserve, safety-modulated (sensitivity 1.0).

**Scoring**: Safety-modulated linear decay.

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
