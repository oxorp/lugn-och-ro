# POI Indicators

> Points of interest density — both positive amenities and negative markers.

## Overview

Eight POI-based indicators measure the presence of amenities and distress markers near each DeSO, accounting for 15.0% of the composite score. All use urbanity-stratified normalization.

## Positive Indicators (Amenities)

### `grocery_density` — Grocery Access

| Property | Value |
|---|---|
| Source | OSM |
| Direction | positive |
| Weight | 0.0400 (4.0%) |
| Scope | urbanity_stratified |

Grocery stores (supermarkets, convenience stores) per 1,000 residents.

### `healthcare_density` — Healthcare Access

| Property | Value |
|---|---|
| Source | OSM |
| Direction | positive |
| Weight | 0.0300 (3.0%) |
| Scope | urbanity_stratified |

Healthcare facilities (clinics, hospitals, pharmacies) per 1,000 residents.

### `restaurant_density` — Restaurant & Café Density

| Property | Value |
|---|---|
| Source | OSM |
| Direction | positive |
| Weight | 0.0200 (2.0%) |
| Scope | urbanity_stratified |

Restaurants and cafés per 1,000 residents. A gentrification/quality-of-life indicator.

### `fitness_density` — Fitness & Sports Access

| Property | Value |
|---|---|
| Source | OSM |
| Direction | positive |
| Weight | 0.0200 (2.0%) |
| Scope | urbanity_stratified |

Gyms, sports centres, and fitness facilities per 1,000 residents.

## Negative Indicators (Distress Markers)

### `gambling_density` — Gambling Venue Density

| Property | Value |
|---|---|
| Source | OSM |
| Direction | negative |
| Weight | 0.0200 (2.0%) |
| Scope | urbanity_stratified |

Gambling venues (betting shops, casinos) per 1,000 residents. Correlates with financial distress.

### `pawn_shop_density` — Pawn Shop Density

| Property | Value |
|---|---|
| Source | OSM |
| Direction | negative |
| Weight | 0.0100 (1.0%) |
| Scope | urbanity_stratified |

Pawn shops (pantbank) per 1,000 residents. Financial distress marker.

### `fast_food_density` — Late-Night Fast Food Density

| Property | Value |
|---|---|
| Source | OSM |
| Direction | negative |
| Weight | 0.0100 (1.0%) |
| Scope | urbanity_stratified |

Fast food restaurants per 1,000 residents. Nighttime economy/disturbance proxy.

## How POI Indicators Work

### Data Flow

```mermaid
graph LR
    A[Overpass API] -->|ingest:pois| B[pois table]
    B -->|assign:poi-deso| C[pois.deso_code assigned]
    C -->|aggregate:poi-indicators| D[indicator_values]
    D -->|normalize:indicators| E[Normalized 0-1]
```

### Density Calculation

```
density = poi_count_in_catchment / (deso_population / 1000)
```

- Catchment radius is defined per POI category in `poi_categories`
- Zero POIs = density of 0.0 (valid data, not NULL)
- NULL population = excluded from density calculation

### POI Categories Table

POI categories are defined in `poi_categories` and control:
- Which OSM tags to query
- Signal type (positive/negative/neutral)
- Whether to display on map (`show_on_map`)
- Minimum tier to view (`display_tier`)
- Associated indicator slug

## Known Issues & Edge Cases

- **OSM coverage varies**: Urban areas have much richer OSM data than rural areas. Urbanity-stratified normalization partially compensates.
- **Category overlap**: Some POIs could fit multiple categories (e.g., a gambling-attached restaurant). Assignment follows primary category.
- **Zero vs NULL**: Zero density means "we checked and there are none." NULL means "no data" (e.g., DeSO population is missing). This distinction matters for normalization.

## Related

- [Master Indicator Reference](/indicators/)
- [POI (OpenStreetMap) Source](/data-sources/poi-openstreetmap)
- [Transit Indicators](/indicators/transit)
- [Urbanity Classification](/methodology/urbanity)
