# Transit Indicators

> Public transport accessibility measured by stop density.

## Overview

One indicator currently covers transit access, with more planned (GTFS-based frequency and commute time).

## `transit_stop_density` — Public Transport Stops

| Property | Value |
|---|---|
| Source | OSM (Overpass API) |
| Unit | per 1,000 inhabitants |
| Direction | positive (higher = better) |
| Weight | 0.0400 (4.0%) |
| Normalization | rank_percentile, **urbanity-stratified** |
| Category | transport |

### What It Measures

Number of public transport stops (bus, tram, metro, train) per 1,000 residents within the DeSO's catchment area. Sourced from OpenStreetMap.

### Why Urbanity-Stratified

Transit stop density is normalized within urbanity tiers because:
- An urban DeSO with 5 stops is poorly served
- A rural DeSO with 5 stops is well served
- National ranking would systematically penalize rural areas

### Planned Enhancements

Future indicators from GTFS data:
- `transit_frequency` — Average departures per hour at nearby stops
- `transit_commute_min` — Estimated commute time to nearest major city center
- `transit_coverage` — % of DeSO within 400m walking distance of a stop

These require GTFS Sverige 2 data from Trafiklab, which is planned but not yet implemented.

## Related

- [Master Indicator Reference](/indicators/)
- [POI Indicators](/indicators/poi)
- [GTFS Transit Source](/data-sources/gtfs-transit)
- [Urbanity Classification](/methodology/urbanity)
