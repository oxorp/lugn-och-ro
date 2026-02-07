<?php

namespace App\Http\Controllers;

use App\Models\PoiCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PoiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'bbox' => 'required|string',
            'zoom' => 'required|integer|min:5|max:20',
            'categories' => 'nullable|string',
        ]);

        $bboxParts = explode(',', $request->string('bbox'));
        if (count($bboxParts) !== 4) {
            return response()->json(['error' => 'bbox must be minLng,minLat,maxLng,maxLat'], 422);
        }

        [$minLng, $minLat, $maxLng, $maxLat] = array_map('floatval', $bboxParts);
        $zoom = $request->integer('zoom');

        $maxTier = $this->zoomToMaxTier($zoom);
        if ($maxTier === 0) {
            return response()->json([]);
        }

        $limit = match (true) {
            $zoom >= 16 => 10000,
            $zoom >= 14 => 5000,
            $zoom >= 12 => 2000,
            $zoom >= 10 => 500,
            default => 200,
        };

        // Only show POIs from categories that are active and have show_on_map enabled
        $visibleCategories = Cache::remember('poi-visible-category-slugs', 3600, fn () => PoiCategory::query()
            ->where('is_active', true)
            ->where('show_on_map', true)
            ->pluck('slug')
            ->all()
        );

        $query = DB::table('pois')
            ->select('id', 'name', 'poi_type', 'category', 'sentiment', 'lat', 'lng')
            ->whereRaw('ST_Intersects(geom, ST_MakeEnvelope(?, ?, ?, ?, 4326))', [
                $minLng, $minLat, $maxLng, $maxLat,
            ])
            ->where('display_tier', '<=', $maxTier)
            ->where('status', 'active')
            ->whereNotNull('geom')
            ->whereIn('category', $visibleCategories);

        if ($request->filled('categories')) {
            $categoryList = explode(',', $request->string('categories'));
            $query->whereIn('category', $categoryList);
        }

        $pois = $query->orderBy('display_tier')->limit($limit)->get();

        return response()->json($pois)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function categories(): JsonResponse
    {
        $categories = Cache::remember('poi-categories-display', 3600, fn () => PoiCategory::query()
            ->where('is_active', true)
            ->where('show_on_map', true)
            ->select('slug', 'name', 'signal', 'display_tier', 'icon', 'color', 'impact_radius_km', 'category_group')
            ->orderBy('display_tier')
            ->orderBy('name')
            ->get()
        );

        return response()->json($categories);
    }

    private function zoomToMaxTier(int $zoom): int
    {
        return match (true) {
            $zoom >= 16 => 5,
            $zoom >= 14 => 4,
            $zoom >= 12 => 3,
            $zoom >= 10 => 2,
            $zoom >= 8 => 1,
            default => 0,
        };
    }
}
