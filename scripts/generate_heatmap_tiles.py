#!/usr/bin/env python3
"""
Generate heatmap PNG tiles from H3 scores.

Reads H3 cell scores from the database, renders them as colored hexagons
onto 256x256 PNG tiles, and applies Gaussian blur to create a smooth
continuous heatmap. Output tiles are served as an XYZ tile layer in OpenLayers.

Usage:
    python3 scripts/generate_heatmap_tiles.py \
        --year=2024 --zoom-min=5 --zoom-max=12 \
        --output=/var/www/storage/app/public/tiles \
        --db-host=postgres --db-name=realestate \
        --db-user=realestate --db-password=secret
"""

import argparse
import math
import os
import sys
import time
from collections import defaultdict

import h3
import numpy as np
from PIL import Image, ImageDraw, ImageFilter
import psycopg2

# Sweden bounding box (EPSG:4326)
SWEDEN_BOUNDS = {
    'west': 10.5,
    'south': 55.2,
    'east': 24.2,
    'north': 69.1,
}


def hex_to_rgb(hex_color):
    """Convert a hex color string to an (r, g, b) tuple."""
    hex_color = hex_color.lstrip('#')
    return (int(hex_color[0:2], 16), int(hex_color[2:4], 16), int(hex_color[4:6], 16))


# Gradient stops matching config/score_colors.php (red -> green)
GRADIENT_STOPS = [
    (0,   hex_to_rgb('#c0392b')),   # Deep red
    (25,  hex_to_rgb('#e74c3c')),   # Red
    (40,  hex_to_rgb('#f39c12')),   # Amber/orange
    (50,  hex_to_rgb('#f1c40f')),   # Yellow
    (60,  hex_to_rgb('#f1c40f')),   # Yellow
    (75,  hex_to_rgb('#27ae60')),   # Green
    (100, hex_to_rgb('#1a7a2e')),   # Deep green
]


def score_to_rgba(score, alpha=180):
    """
    Map a score (0-100) to an RGBA color.
    Red (bad, 0) -> Yellow (mixed, 50) -> Green (good, 100).
    Linearly interpolates between gradient stops defined in GRADIENT_STOPS.
    """
    t = max(0.0, min(100.0, score))

    for i in range(len(GRADIENT_STOPS) - 1):
        lo_score, lo_rgb = GRADIENT_STOPS[i]
        hi_score, hi_rgb = GRADIENT_STOPS[i + 1]
        if t >= lo_score and t <= hi_score:
            if hi_score == lo_score:
                s = 0.0
            else:
                s = (t - lo_score) / (hi_score - lo_score)
            r = int(lo_rgb[0] + (hi_rgb[0] - lo_rgb[0]) * s)
            g = int(lo_rgb[1] + (hi_rgb[1] - lo_rgb[1]) * s)
            b = int(lo_rgb[2] + (hi_rgb[2] - lo_rgb[2]) * s)
            return (r, g, b, alpha)

    last_rgb = GRADIENT_STOPS[-1][1]
    return (last_rgb[0], last_rgb[1], last_rgb[2], alpha)


def tile_to_bbox(z, x, y):
    """Convert tile coordinates to geographic bounding box (west, south, east, north)."""
    n = 2 ** z
    west = x / n * 360.0 - 180.0
    east = (x + 1) / n * 360.0 - 180.0
    north = math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * y / n))))
    south = math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * (y + 1) / n))))
    return (west, south, east, north)


def latlng_to_pixel(lat, lng, z, tile_x, tile_y, tile_size=256):
    """Convert lat/lng to pixel coordinates within a specific tile."""
    n = 2 ** z
    # World pixel coordinates
    world_x = (lng + 180.0) / 360.0 * n * tile_size
    lat_rad = math.radians(lat)
    world_y = (1.0 - math.log(math.tan(lat_rad) + 1.0 / math.cos(lat_rad)) / math.pi) / 2.0 * n * tile_size
    # Pixel within tile
    px = world_x - tile_x * tile_size
    py = world_y - tile_y * tile_size
    return (px, py)


def tile_covers_sweden(z, x, y):
    """Check if a tile intersects Sweden's bounding box."""
    west, south, east, north = tile_to_bbox(z, x, y)
    return not (east < SWEDEN_BOUNDS['west'] or west > SWEDEN_BOUNDS['east'] or
                north < SWEDEN_BOUNDS['south'] or south > SWEDEN_BOUNDS['north'])


def get_tile_range(z):
    """Get the range of tiles that cover Sweden at a given zoom level."""
    n = 2 ** z

    def lng_to_tile_x(lng):
        return int((lng + 180.0) / 360.0 * n)

    def lat_to_tile_y(lat):
        lat_rad = math.radians(lat)
        return int((1.0 - math.log(math.tan(lat_rad) + 1.0 / math.cos(lat_rad)) / math.pi) / 2.0 * n)

    x_min = max(0, lng_to_tile_x(SWEDEN_BOUNDS['west']) - 1)
    x_max = min(n - 1, lng_to_tile_x(SWEDEN_BOUNDS['east']) + 1)
    y_min = max(0, lat_to_tile_y(SWEDEN_BOUNDS['north']) - 1)
    y_max = min(n - 1, lat_to_tile_y(SWEDEN_BOUNDS['south']) + 1)

    return x_min, x_max, y_min, y_max


def choose_resolution(zoom):
    """Choose H3 resolution for a given zoom level to balance detail vs performance."""
    if zoom <= 6:
        return 5
    elif zoom <= 8:
        return 6
    elif zoom <= 10:
        return 7
    else:
        return 8


def load_h3_scores(conn, year):
    """Load all H3 scores from database, grouped by resolution."""
    scores = {}
    cur = conn.cursor()
    cur.execute("""
        SELECT h3_index, resolution, score_smoothed
        FROM h3_scores
        WHERE year = %s AND score_smoothed IS NOT NULL
    """, (year,))

    for h3_index, resolution, score in cur.fetchall():
        if resolution not in scores:
            scores[resolution] = {}
        scores[resolution][h3_index] = float(score)

    cur.close()

    for res, data in scores.items():
        print(f"  Loaded {len(data):,} H3 cells at resolution {res}")

    return scores


def build_spatial_index(h3_data):
    """Build a coarse spatial index: map (lat_bucket, lng_bucket) -> list of (h3_index, score)."""
    index = defaultdict(list)
    for h3_index, score in h3_data.items():
        lat, lng = h3.cell_to_latlng(h3_index)
        # Bucket by 0.5 degree grid
        lat_bucket = int(lat * 2)
        lng_bucket = int(lng * 2)
        index[(lat_bucket, lng_bucket)].append((h3_index, score, lat, lng))
    return index


def get_cells_in_bounds(spatial_index, west, south, east, north, padding=0.02):
    """Get H3 cells within bounds using spatial index."""
    west -= padding
    south -= padding
    east += padding
    north += padding

    lat_min_bucket = int(south * 2) - 1
    lat_max_bucket = int(north * 2) + 1
    lng_min_bucket = int(west * 2) - 1
    lng_max_bucket = int(east * 2) + 1

    results = []
    for lat_b in range(lat_min_bucket, lat_max_bucket + 1):
        for lng_b in range(lng_min_bucket, lng_max_bucket + 1):
            for h3_index, score, lat, lng in spatial_index.get((lat_b, lng_b), []):
                if south <= lat <= north and west <= lng <= east:
                    results.append((h3_index, score))
    return results


def render_tile(z, x, y, cells_in_tile, blur_radius):
    """Render a single 256x256 RGBA tile."""
    if not cells_in_tile:
        return None

    img = Image.new('RGBA', (256, 256), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    for h3_index, score in cells_in_tile:
        color = score_to_rgba(score, alpha=200)
        boundary = h3.cell_to_boundary(h3_index)
        pixel_coords = []
        for lat, lng in boundary:
            px, py = latlng_to_pixel(lat, lng, z, x, y)
            pixel_coords.append((px, py))

        if len(pixel_coords) >= 3:
            draw.polygon(pixel_coords, fill=color)

    # Apply Gaussian blur to smooth hex edges into a continuous gradient
    img = img.filter(ImageFilter.GaussianBlur(radius=blur_radius))

    # Check if tile is effectively empty (all transparent)
    extrema = img.getextrema()
    if extrema[3][1] < 2:  # Max alpha < 2
        return None

    return img


def generate_tiles(args):
    """Main tile generation loop."""
    print(f"Connecting to database {args.db_name}@{args.db_host}...")
    conn = psycopg2.connect(
        host=args.db_host,
        port=args.db_port,
        dbname=args.db_name,
        user=args.db_user,
        password=args.db_password,
    )

    print(f"Loading H3 scores for year {args.year}...")
    all_scores = load_h3_scores(conn, args.year)
    conn.close()

    if not all_scores:
        print("ERROR: No H3 scores found. Run compute:scores and project:scores-to-h3 first.")
        sys.exit(1)

    # Build spatial indexes per resolution
    print("Building spatial indexes...")
    spatial_indexes = {}
    for res, data in all_scores.items():
        spatial_indexes[res] = build_spatial_index(data)
        print(f"  Resolution {res}: {len(data):,} cells indexed")

    output_base = os.path.join(args.output, str(args.year))
    total_tiles = 0
    rendered_tiles = 0
    empty_tiles = 0
    start_time = time.time()

    for z in range(args.zoom_min, args.zoom_max + 1):
        zoom_start = time.time()
        res = choose_resolution(z)
        h3_data = all_scores.get(res, {})
        spatial_index = spatial_indexes.get(res, {})

        if not h3_data:
            print(f"  WARNING: No data at resolution {res} for zoom {z}")
            continue

        # Adjust blur radius based on zoom (more blur at lower zoom)
        if z <= 6:
            blur_radius = 6
        elif z <= 9:
            blur_radius = 5
        else:
            blur_radius = 4

        x_min, x_max, y_min, y_max = get_tile_range(z)
        zoom_tiles = 0
        zoom_rendered = 0

        for x_tile in range(x_min, x_max + 1):
            for y_tile in range(y_min, y_max + 1):
                if not tile_covers_sweden(z, x_tile, y_tile):
                    continue

                total_tiles += 1
                zoom_tiles += 1

                bounds = tile_to_bbox(z, x_tile, y_tile)
                cells = get_cells_in_bounds(spatial_index, *bounds)

                img = render_tile(z, x_tile, y_tile, cells, blur_radius)

                if img is None:
                    empty_tiles += 1
                    continue

                tile_dir = os.path.join(output_base, str(z), str(x_tile))
                os.makedirs(tile_dir, exist_ok=True)
                tile_path = os.path.join(tile_dir, f"{y_tile}.png")
                img.save(tile_path, 'PNG', optimize=True)
                rendered_tiles += 1
                zoom_rendered += 1

        elapsed = time.time() - zoom_start
        print(f"  Zoom {z}: {zoom_rendered}/{zoom_tiles} tiles rendered "
              f"(res={res}, blur={blur_radius}, {elapsed:.1f}s)")

    total_elapsed = time.time() - start_time
    print(f"\nDone! {rendered_tiles:,} tiles rendered, {empty_tiles:,} empty, "
          f"{total_tiles:,} total in {total_elapsed:.0f}s")
    print(f"Output: {output_base}/")


def main():
    parser = argparse.ArgumentParser(description='Generate heatmap PNG tiles from H3 scores')
    parser.add_argument('--year', type=int, default=2024, help='Score year')
    parser.add_argument('--zoom-min', type=int, default=5, help='Minimum zoom level')
    parser.add_argument('--zoom-max', type=int, default=12, help='Maximum zoom level')
    parser.add_argument('--output', type=str, default='/var/www/storage/app/public/tiles',
                        help='Output directory for tiles')
    parser.add_argument('--db-host', type=str, default='postgres')
    parser.add_argument('--db-port', type=int, default=5432)
    parser.add_argument('--db-name', type=str, default='realestate')
    parser.add_argument('--db-user', type=str, default='realestate')
    parser.add_argument('--db-password', type=str, default='secret')

    args = parser.parse_args()
    generate_tiles(args)


if __name__ == '__main__':
    main()
