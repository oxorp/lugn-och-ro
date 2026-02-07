# DeSO GeoJSON

> `GET /api/deso/geojson` — DeSO polygon boundaries for map rendering.

## Endpoint

```
GET /api/deso/geojson
```

**Controller**: `DesoController@geojson`

## Response

Returns a GeoJSON FeatureCollection with all 6,160 DeSO polygons.

**Cache**: 24 hours (`max-age=86400`)

### Static File Optimization

If `public/data/deso.geojson` exists, it's served directly as a static file (fastest path). Otherwise, geometries are generated from the database using PostGIS `ST_AsGeoJSON()`.

### Feature Properties

```json
{
  "type": "Feature",
  "geometry": { "type": "MultiPolygon", "coordinates": [...] },
  "properties": {
    "deso_code": "0114A0010",
    "deso_name": "Väsby centrum",
    "kommun_code": "0114",
    "kommun_name": "Upplands Väsby",
    "lan_code": "01",
    "lan_name": "Stockholms län",
    "area_km2": 1.23
  }
}
```

## Related

- [API Overview](/api/)
- [DeSO Scores](/api/deso-scores)
- [Map Rendering](/frontend/map-rendering)
