# Heatmap Tiles

> Pre-rendered PNG tiles for the map heatmap overlay.

## Endpoint

```
GET /tiles/{year}/{z}/{x}/{y}.png
```

**Controller**: `app/Http/Controllers/TileController.php`
**Route name**: `tiles.serve`

## Parameters

| Parameter | Type | Description |
|---|---|---|
| `year` | int | Score year (e.g., `2025`) |
| `z` | int | Zoom level (5–12) |
| `x` | int | Tile column |
| `y` | int | Tile row |

## How It Works

Tiles are **pre-rendered** by a Python script and served as static PNG files. The tile controller (`TileController.php`) serves them from `storage/app/public/tiles/{year}/{z}/{x}/{y}.png`.

For missing tiles (ocean, areas outside Sweden), a **1×1 transparent PNG** is returned with 24-hour cache headers.

## Tile Generation

### Artisan Command

```bash
php artisan generate:heatmap-tiles --year=2025 --zoom-min=5 --zoom-max=12
```

This wraps the Python script `scripts/generate_heatmap_tiles.py` and passes database credentials from Laravel config.

### Python Script

**File**: `scripts/generate_heatmap_tiles.py`
**Dependencies**: `h3`, `numpy`, `Pillow`, `psycopg2`

The script:

1. **Loads H3 scores** from the `h3_scores` table (grouped by resolution)
2. **Builds spatial indexes** using 0.5° lat/lng grid buckets
3. **For each tile** at each zoom level:
   - Determines which H3 cells intersect the tile bounds
   - Renders colored hexagons using the score→color gradient
   - Applies Gaussian blur for smooth edges
   - Saves as optimized PNG

### Resolution Mapping

H3 resolution is chosen based on zoom level:

| Map Zoom | H3 Resolution | Cell Size |
|---|---|---|
| ≤ 6 | 5 | ~252 km² |
| 7–8 | 6 | ~36 km² |
| 9–10 | 7 | ~5.2 km² |
| 11–12 | 8 | ~0.74 km² |

### Blur Radius

Gaussian blur creates a smooth continuous gradient:

| Zoom | Blur Radius |
|---|---|
| ≤ 6 | 6 px |
| 7–9 | 5 px |
| 10–12 | 4 px |

### Color Scale

Same purple→green gradient as the interactive map:

| Score | Color Description |
|---|---|
| 0 | Deep purple |
| 25 | Magenta |
| 50 | Yellow-orange |
| 75 | Green-yellow |
| 100 | Deep green |

### Coverage

Tiles are only generated for Sweden's bounding box:
- West: 10.5°, South: 55.2°, East: 24.2°, North: 69.1°

## Caching

```
Cache-Control: public, max-age=86400
Access-Control-Allow-Origin: *
```

Tiles are cached for 24 hours at the HTTP level. Regenerate tiles after score updates.

## Related

- [Scoring Engine](/architecture/scoring-engine)
- [H3 Endpoints](/api/h3-endpoints)
- [Artisan Commands](/operations/artisan-commands)
