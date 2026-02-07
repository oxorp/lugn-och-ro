# GTFS Transit

> Public transport data from GTFS feeds — planned but not yet implemented.

## Status: :yellow_circle: Planned

GTFS transit data ingestion is not yet implemented. See the data pipeline specification for planned approach.

## Planned Data Sources

| Source | Region | GTFS Feed |
|---|---|---|
| Trafiklab GTFS Sverige 2 | All Sweden | National consolidated feed |
| SL | Stockholm | Regional transit authority |
| Västtrafik | Gothenburg/Western Sweden | Regional transit authority |
| Skånetrafiken | Malmö/Scania | Regional transit authority |

## Planned Indicators

| Indicator | Unit | Direction | Description |
|---|---|---|---|
| `transit_frequency` | departures/hour | positive | Average departures per hour at nearby stops |
| `transit_commute_min` | minutes | negative | Estimated commute time to nearest major city center |
| `transit_coverage` | percent | positive | % of DeSO within 400m walking distance of a stop |

## Current Coverage

Transit is partially covered by the `transit_stop_density` indicator sourced from OpenStreetMap, which counts bus stops, train stations, and tram stops per 1,000 residents.

## Related

- [Data Sources Overview](/data-sources/)
- [Transit Indicators](/indicators/transit)
