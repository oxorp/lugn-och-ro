# POI (OpenStreetMap)

> Points of interest from OpenStreetMap via the Overpass API.

## Overview

OpenStreetMap (OSM) is the primary source for POI data. The `OverpassService` queries the Overpass API to find points of interest across Sweden, categorized into positive amenities and negative markers.

## API Details

| Property | Value |
|---|---|
| API URL | `https://overpass-api.de/api/interpreter` |
| Protocol | POST with Overpass QL query |
| Auth | None |
| Cost | Free |
| Rate limits | Reasonable use; no hard published limits |
| Coverage | All of Sweden (volunteer-contributed data) |

## Query Format

```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
nwr["amenity"="place_of_worship"]["religion"="muslim"](area.sweden);
out center;
```

Queries are built dynamically from `poi_categories.osm_tags`.

## POI Categories

### Positive (Amenities)

| Category | OSM Tags | Catchment | Signal |
|---|---|---|---|
| Grocery Stores | `shop=supermarket/convenience/greengrocer` | 1.5 km | positive |
| Healthcare | `amenity=hospital/clinic/doctors` | 3.0 km | positive |
| Restaurants & Cafés | `amenity=restaurant/cafe` | 1.0 km | positive |
| Gyms & Fitness | `leisure=fitness_centre/sports_centre`, `sport=padel` | 2.0 km | positive |
| Public Transport | `highway=bus_stop`, `railway=station/halt/tram_stop` | 1.0 km | positive |
| Pharmacies | `amenity=pharmacy` | 1.0 km | positive |
| Libraries | `amenity=library` | 1.0 km | positive |
| Parks | `leisure=park` | 0.5 km | positive |
| Nature Reserves | `leisure=nature_reserve` | 1.0 km | positive |
| Marinas | `leisure=marina` | 1.0 km | positive |
| Swimming | `leisure=swimming_pool/bathing_place` | 1.0 km | positive |
| Cultural Venues | `tourism=museum`, `amenity=theatre` | 1.0 km | positive |
| Bookshops | `shop=books` | 0.5 km | positive |

### Negative (Distress/Nuisance Markers)

| Category | OSM Tags | Catchment | Signal |
|---|---|---|---|
| Gambling Venues | `shop=bookmaker/lottery`, `amenity=gambling/casino` | 1.5 km | negative |
| Pawn Shops | `shop=pawnbroker` | 1.5 km | negative |
| Fast Food | `amenity=fast_food` | 1.0 km | negative |
| Wastewater Plants | `man_made=wastewater_plant` | 2.0 km | negative |
| Landfills | `landuse=landfill` | 3.0 km | negative |
| Quarries | `landuse=quarry` | 2.0 km | negative |
| Prisons | `amenity=prison` | 2.0 km | negative |
| Airports | `aeroway=aerodrome` | 5.0 km | negative |
| Wind Turbines | `power=generator` + `generator:source=wind` | 1.0 km | negative |
| Shooting Ranges | `sport=shooting` | 1.5 km | negative |
| Paper Mills | `man_made=works` + `product=paper/pulp` | 5.0 km | negative |
| Nightclubs | `amenity=nightclub` | 0.5 km | negative |
| Recycling Stations | `amenity=recycling` | 0.2 km | negative |
| Homeless Shelters | `social_facility=shelter` + `social_facility:for=homeless` | 0.5 km | negative |

## Ingestion

```bash
php artisan ingest:pois
```

### Process

1. Read active categories from `poi_categories`
2. For each category, build Overpass QL query from `osm_tags`
3. POST to Overpass API
4. Parse response elements (nodes, ways, relations → lat/lng)
5. Assign DeSO codes via PostGIS point-in-polygon
6. Upsert into `pois` table

## Known Issues & Edge Cases

- **Urban bias**: OSM data is much richer in urban areas. Urbanity-stratified normalization compensates.
- **Data freshness**: OSM is continuously updated by volunteers. Monthly re-ingestion catches changes.
- **Coordinate extraction**: Ways and relations don't have direct coordinates — use `out center` to get centroids.
- **Compound tags**: Some categories require compound tag queries (e.g., wind turbines need both `power=generator` AND `generator:source=wind`). These use the `_and` key in `osm_tags`.
- **Zero is data**: A DeSO with 0 POIs of a type gets density 0.0 (not NULL). NULL means "not measured."

## Related

- [Data Sources Overview](/data-sources/)
- [POI Indicators](/indicators/poi)
- [POI (Google Places)](/data-sources/poi-google-places)
