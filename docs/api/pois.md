# POIs

> `GET /api/pois` and `GET /api/pois/categories` — Point of interest data for map display.

## Endpoints

### `GET /api/pois`

Returns POIs filtered by viewport and active categories. Used by the map layer.

**Controller**: `PoiController@index`

### `GET /api/pois/categories`

Returns all POI category definitions for the map controls UI.

**Controller**: `PoiController@categories`

### `GET /api/deso/{code}/pois`

Returns POIs near a specific DeSO (within 3km of centroid).

**Controller**: `DesoController@pois`

## Related

- [API Overview](/api/)
- [POI Display](/frontend/poi-display)
- [POI Indicators](/indicators/poi)
