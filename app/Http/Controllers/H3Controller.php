<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class H3Controller extends Controller
{
    // Sweden's approximate bounding box — clamp viewport to avoid computing cells over the ocean
    private const SWEDEN_MIN_LNG = 10.5;

    private const SWEDEN_MAX_LNG = 24.2;

    private const SWEDEN_MIN_LAT = 55.2;

    private const SWEDEN_MAX_LAT = 69.1;

    /**
     * Lightweight scores endpoint: returns all H3 index + score pairs.
     */
    public function scores(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year - 1);
        $smoothed = $request->boolean('smoothed', true);
        $scoreCol = $smoothed ? 'score_smoothed' : 'score_raw';

        $scores = DB::table('h3_scores')
            ->where('year', $year)
            ->where('resolution', 8)
            ->whereNotNull($scoreCol)
            ->select('h3_index', DB::raw("{$scoreCol} as score"), 'trend_1y', 'primary_deso_code')
            ->get();

        return response()->json($scores)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Viewport-based loading: returns H3 scores filtered by bounding box and zoom.
     *
     * All resolutions use the same fast pattern: fill bbox at target resolution,
     * then join pre-computed h3_scores at that resolution. Lower-res scores (5/6/7)
     * are pre-aggregated by smooth:h3-scores.
     */
    public function viewport(Request $request): JsonResponse
    {
        $request->validate([
            'bbox' => 'required|string',
            'zoom' => 'required|integer',
        ]);

        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', explode(',', $request->bbox));

        // Clamp to Sweden's bounds to avoid computing cells over the ocean/other countries
        $minLng = max($minLng, self::SWEDEN_MIN_LNG);
        $minLat = max($minLat, self::SWEDEN_MIN_LAT);
        $maxLng = min($maxLng, self::SWEDEN_MAX_LNG);
        $maxLat = min($maxLat, self::SWEDEN_MAX_LAT);

        // If bbox is entirely outside Sweden, return empty
        if ($minLng >= $maxLng || $minLat >= $maxLat) {
            return response()->json([
                'resolution' => 8,
                'count' => 0,
                'features' => [],
            ])->header('Cache-Control', 'public, max-age=300');
        }

        $year = $request->integer('year', now()->year - 1);
        $smoothed = $request->boolean('smoothed', true);
        $scoreCol = $smoothed ? 'score_smoothed' : 'score_raw';
        $zoom = $request->integer('zoom');
        $resolution = $this->zoomToResolution($zoom);

        $features = DB::select("
            SELECT hs.h3_index, hs.{$scoreCol} AS score, hs.primary_deso_code
            FROM h3_scores hs
            JOIN (
                SELECT h3_polygon_to_cells(ST_MakeEnvelope(?, ?, ?, ?, 4326), ?)::text AS h3_index
            ) viewport ON viewport.h3_index = hs.h3_index
            WHERE hs.year = ? AND hs.resolution = ? AND hs.{$scoreCol} IS NOT NULL
        ", [$minLng, $minLat, $maxLng, $maxLat, $resolution, $year, $resolution]);

        return response()->json([
            'resolution' => $resolution,
            'count' => count($features),
            'features' => $features,
        ])->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Map OpenLayers zoom levels to H3 resolutions.
     */
    private function zoomToResolution(int $zoom): int
    {
        return match (true) {
            $zoom <= 6 => 5,
            $zoom <= 8 => 6,
            $zoom <= 10 => 7,
            default => 8,
        };
    }

    /**
     * Return available smoothing configs for admin UI.
     */
    public function smoothingConfigs(): JsonResponse
    {
        $configs = DB::table('smoothing_configs')
            ->select('id', 'name', 'self_weight', 'neighbor_weight', 'k_rings', 'decay_function', 'is_active')
            ->orderBy('id')
            ->get();

        return response()->json($configs);
    }
}
