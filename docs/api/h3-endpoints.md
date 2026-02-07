# H3 Endpoints

> H3 hexagonal score endpoints for multi-resolution map display.

## Endpoints

### `GET /api/h3/scores`

Returns all H3 scores at resolution 8.

**Controller**: `H3Controller@scores`

| Param | Type | Default | Description |
|---|---|---|---|
| `year` | integer | Previous year | Data year |
| `smoothed` | boolean | true | Use smoothed or raw scores |

**Cache**: 1 hour

### `GET /api/h3/viewport`

Returns H3 scores filtered by bounding box and zoom level.

**Controller**: `H3Controller@viewport`

| Param | Type | Required | Description |
|---|---|---|---|
| `bbox` | string | Yes | `minLng,minLat,maxLng,maxLat` |
| `zoom` | integer | Yes | OpenLayers zoom level |
| `year` | integer | No | Data year |
| `smoothed` | boolean | No | Use smoothed scores (default: true) |

**Cache**: 5 minutes

#### Zoom → Resolution Mapping

| Zoom Level | H3 Resolution | Hex Area |
|---|---|---|
| ≤6 | 5 | ~252 km² |
| 7–8 | 6 | ~36 km² |
| 9–10 | 7 | ~5.16 km² |
| 11+ | 8 | ~0.74 km² |

#### Viewport Clamping

Requests are clamped to Sweden's bounding box to avoid computing H3 cells over water:
- Lat: 55.2 – 69.1
- Lng: 10.5 – 24.2

### `GET /api/h3/smoothing-configs`

Returns available smoothing configurations for admin UI.

**Controller**: `H3Controller@smoothingConfigs`

## Related

- [API Overview](/api/)
- [Spatial Framework](/architecture/spatial-framework)
