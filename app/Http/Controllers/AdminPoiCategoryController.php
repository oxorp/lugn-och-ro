<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePoiCategoryRequest;
use App\Models\PoiCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminPoiCategoryController extends Controller
{
    public function index(): Response
    {
        $categories = PoiCategory::query()
            ->orderByRaw("CASE signal WHEN 'positive' THEN 1 WHEN 'neutral' THEN 2 WHEN 'negative' THEN 3 END")
            ->orderBy('safety_sensitivity', 'desc')
            ->get()
            ->map(function (PoiCategory $cat) {
                $poiCount = DB::table('pois')
                    ->where('category', $cat->slug)
                    ->where('status', 'active')
                    ->count();

                return [
                    'id' => $cat->id,
                    'slug' => $cat->slug,
                    'name' => $cat->name,
                    'signal' => $cat->signal,
                    'safety_sensitivity' => (float) $cat->safety_sensitivity,
                    'catchment_km' => (float) $cat->catchment_km,
                    'icon' => $cat->icon,
                    'color' => $cat->color,
                    'is_active' => $cat->is_active,
                    'poi_count' => $poiCount,
                ];
            });

        $exampleSafe = $this->exampleComputation($categories, 0.90);
        $exampleUnsafe = $this->exampleComputation($categories, 0.15);

        return Inertia::render('admin/poi-categories', [
            'categories' => $categories,
            'exampleSafe' => $exampleSafe,
            'exampleUnsafe' => $exampleUnsafe,
        ]);
    }

    public function update(UpdatePoiCategoryRequest $request, PoiCategory $poiCategory): RedirectResponse
    {
        $poiCategory->update($request->validated());

        Cache::forget('poi_category_settings');
        Cache::forget('poi-categories-display');
        Cache::forget('poi-visible-category-slugs');

        return back()->with('success', "Updated {$poiCategory->name}");
    }

    /**
     * Compute example effective distances for the admin preview panel.
     *
     * @return array<int, array{slug: string, name: string, physical_m: int, effective_m: int, decay: float}>
     */
    private function exampleComputation(Collection $categories, float $safetyScore): array
    {
        $physicalDistance = 500;

        return $categories
            ->filter(fn ($cat) => $cat['signal'] === 'positive')
            ->map(function ($cat) use ($physicalDistance, $safetyScore) {
                $sensitivity = $cat['safety_sensitivity'];
                $maxDistanceM = $cat['catchment_km'] * 1000;
                $riskPenalty = (1.0 - $safetyScore) * $sensitivity;
                $effectiveM = $physicalDistance * (1.0 + $riskPenalty);
                $decay = max(0, 1 - $effectiveM / $maxDistanceM);

                return [
                    'slug' => $cat['slug'],
                    'name' => $cat['name'],
                    'physical_m' => $physicalDistance,
                    'effective_m' => (int) round($effectiveM),
                    'decay' => round($decay, 2),
                ];
            })
            ->values()
            ->toArray();
    }
}
