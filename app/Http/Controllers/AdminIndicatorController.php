<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateIndicatorRequest;
use App\Models\Indicator;
use App\Models\PoiCategory;
use App\Models\TenantIndicatorWeight;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminIndicatorController extends Controller
{
    public function index(): Response
    {
        $tenant = currentTenant();

        $indicators = Indicator::query()
            ->orderBy('display_order')
            ->get()
            ->map(function (Indicator $indicator) use ($tenant) {
                $latestYear = DB::table('indicator_values')
                    ->where('indicator_id', $indicator->id)
                    ->max('year');

                $coverage = $latestYear
                    ? DB::table('indicator_values')
                        ->where('indicator_id', $indicator->id)
                        ->where('year', $latestYear)
                        ->whereNotNull('raw_value')
                        ->count()
                    : 0;

                $totalDesos = DB::table('deso_areas')->count();

                // Read weight/direction/is_active from tenant weights if available, else from indicator defaults
                $tenantWeight = $tenant
                    ? TenantIndicatorWeight::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('indicator_id', $indicator->id)
                        ->first()
                    : null;

                return [
                    'id' => $indicator->id,
                    'slug' => $indicator->slug,
                    'name' => $indicator->name,
                    'source' => $indicator->source,
                    'category' => $indicator->category,
                    'direction' => $tenantWeight?->direction ?? $indicator->direction,
                    'weight' => (float) ($tenantWeight?->weight ?? $indicator->weight),
                    'normalization' => $indicator->normalization,
                    'normalization_scope' => $indicator->normalization_scope,
                    'is_active' => $tenantWeight?->is_active ?? $indicator->is_active,
                    'is_free_preview' => $indicator->is_free_preview,
                    'latest_year' => $latestYear,
                    'coverage' => $coverage,
                    'total_desos' => $totalDesos,
                    'description_short' => $indicator->description_short,
                    'description_long' => $indicator->description_long,
                    'methodology_note' => $indicator->methodology_note,
                    'national_context' => $indicator->national_context,
                    'source_name' => $indicator->source_name,
                    'source_url' => $indicator->source_url,
                    'update_frequency' => $indicator->update_frequency,
                ];
            });

        $urbanityDistribution = DB::table('deso_areas')
            ->selectRaw("COALESCE(urbanity_tier, 'unclassified') as tier, COUNT(*) as count")
            ->groupBy('urbanity_tier')
            ->pluck('count', 'tier');

        $poiCategories = PoiCategory::query()
            ->orderBy('category_group')
            ->orderBy('name')
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
                    'icon' => $cat->icon,
                    'color' => $cat->color,
                    'display_tier' => $cat->display_tier,
                    'category_group' => $cat->category_group,
                    'indicator_slug' => $cat->indicator_slug,
                    'is_active' => $cat->is_active,
                    'show_on_map' => $cat->show_on_map,
                    'poi_count' => $poiCount,
                ];
            });

        return Inertia::render('admin/indicators', [
            'indicators' => $indicators,
            'urbanityDistribution' => $urbanityDistribution,
            'poiCategories' => $poiCategories,
        ]);
    }

    public function update(UpdateIndicatorRequest $request, Indicator $indicator): RedirectResponse
    {
        $validated = $request->validated();
        $tenant = currentTenant();

        // Validate max 2 free preview indicators per display category
        if (($validated['is_free_preview'] ?? false) === true) {
            $displayCategory = $this->getDisplayCategory($indicator->slug);

            if ($displayCategory) {
                $categorySlugs = config("indicator_categories.{$displayCategory}.indicators", []);
                $currentFreeCount = Indicator::whereIn('slug', $categorySlugs)
                    ->where('is_free_preview', true)
                    ->where('id', '!=', $indicator->id)
                    ->count();

                if ($currentFreeCount >= 2) {
                    return back()->withErrors([
                        'is_free_preview' => "Max 2 fria f\u{00F6}rhandsgranskningsindikatorer per kategori. Inaktivera en annan i '{$displayCategory}' f\u{00F6}rst.",
                    ]);
                }
            }
        }

        // Always update global indicator metadata (descriptions, normalization, source info)
        $globalFields = array_intersect_key($validated, array_flip([
            'normalization', 'normalization_scope', 'is_free_preview',
            'description_short', 'description_long', 'methodology_note',
            'national_context', 'source_name', 'source_url', 'update_frequency',
        ]));

        if (! empty($globalFields)) {
            $indicator->update($globalFields);
        }

        // Update tenant-specific weight/direction/is_active
        if ($tenant) {
            TenantIndicatorWeight::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'indicator_id' => $indicator->id,
                ],
                [
                    'weight' => $validated['weight'],
                    'direction' => $validated['direction'],
                    'is_active' => $validated['is_active'],
                ],
            );
        }

        // Also keep the indicator table defaults in sync (for new tenants)
        $indicator->update([
            'weight' => $validated['weight'],
            'direction' => $validated['direction'],
            'is_active' => $validated['is_active'],
        ]);

        return back()->with('success', "Updated {$indicator->name}");
    }

    /**
     * Find which display category (safety/economy/education/proximity) an indicator belongs to.
     */
    private function getDisplayCategory(string $slug): ?string
    {
        foreach (config('indicator_categories') as $key => $catConfig) {
            if (in_array($slug, $catConfig['indicators'], true)) {
                return $key;
            }
        }

        return null;
    }

    public function updatePoiCategory(Request $request, PoiCategory $poiCategory): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'show_on_map' => ['required', 'boolean'],
            'display_tier' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'signal' => ['sometimes', Rule::in(['positive', 'negative', 'neutral'])],
        ]);

        $poiCategory->update($validated);

        // Clear cached category lists so changes take effect immediately
        Cache::forget('poi-categories-display');
        Cache::forget('poi-visible-category-slugs');

        return back()->with('success', "Updated {$poiCategory->name}");
    }
}
