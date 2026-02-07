# POI (Google Places)

> Commercial POI data from Google Places API — planned for gap-filling.

## Status: :yellow_circle: Planned

Google Places API integration is defined in POI categories but not yet active. The `premium_grocery` category has `google_types: ['supermarket', 'grocery_or_supermarket']` configured but `is_active: false`.

## Planned Use Cases

| Use Case | Rationale |
|---|---|
| Premium grocery detection | OSM doesn't distinguish ICA Maxi from ICA Nära |
| Commercial POI gaps | Google has better coverage for chain restaurants, gyms |
| Name-based filtering | Identify specific chains (Paradiset, upscale ICA) |

## API Details

| Property | Value |
|---|---|
| API URL | Google Places API (Nearby Search) |
| Auth | API key required |
| Cost | Paid (per-request pricing) |
| Coverage | Excellent for commercial establishments |

## Design Principle

OSM is the primary source. Google Places fills specific gaps where OSM coverage is insufficient or lacks the metadata needed for quality differentiation.

## Related

- [Data Sources Overview](/data-sources/)
- [POI (OpenStreetMap)](/data-sources/poi-openstreetmap)
- [POI Indicators](/indicators/poi)
