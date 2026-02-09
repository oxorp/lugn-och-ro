<?php

namespace App\Http\Controllers;

use App\Enums\DataTier;
use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\PoiCategory;
use App\Services\DataTieringService;
use App\Services\PreviewStatsService;
use App\Services\ProximityScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    private const AREA_WEIGHT = 0.70;

    private const PROXIMITY_WEIGHT = 0.30;

    public function __construct(
        private DataTieringService $tiering,
        private ProximityScoreService $proximityService,
        private PreviewStatsService $previewStats,
    ) {}

    public function show(Request $request, float $lat, float $lng): JsonResponse
    {
        $tier = $this->tiering->resolveEffectiveTier($request->user());

        // 1. Find which DeSO this point falls in (PostGIS point-in-polygon)
        $deso = DB::selectOne('
            SELECT deso_code, kommun_code, kommun_name, lan_code, area_km2, urbanity_tier
            FROM deso_areas
            WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ', [$lng, $lat]);

        if (! $deso) {
            return response()->json(['error' => 'Location outside Sweden'], 404);
        }

        // 2. Get composite score for this DeSO (latest year)
        $score = CompositeScore::where('deso_code', $deso->deso_code)
            ->orderByDesc('year')
            ->first();

        $areaScore = $score ? round((float) $score->score, 1) : null;

        // 3. Compute proximity score (cached by ~100m grid cell)
        $proximity = $this->proximityService->scoreCached($lat, $lng);
        $proximityScore = round($proximity->compositeScore(), 1);

        // 4. Blend area + proximity scores
        $blendedScore = $this->blendScores($areaScore, $proximityScore);

        $scoreData = $score ? [
            'value' => $blendedScore,
            'area_score' => $areaScore,
            'proximity_score' => $proximityScore,
            'trend_1y' => $score->trend_1y ? round((float) $score->trend_1y, 1) : null,
            'label' => $this->scoreLabel($blendedScore),
            'top_positive' => $score->top_positive,
            'top_negative' => $score->top_negative,
            'factor_scores' => $score->factor_scores,
            'raw_score_before_penalties' => $score->raw_score_before_penalties ? round((float) $score->raw_score_before_penalties, 1) : null,
            'penalties_applied' => $score->penalties_applied,
            'history' => null, // populated below for paid tiers
        ] : [
            'value' => $proximityScore > 0 ? round($proximityScore * self::PROXIMITY_WEIGHT + 50 * self::AREA_WEIGHT, 1) : null,
            'area_score' => null,
            'proximity_score' => $proximityScore,
            'trend_1y' => null,
            'label' => null,
            'top_positive' => null,
            'top_negative' => null,
            'factor_scores' => null,
            'raw_score_before_penalties' => null,
            'penalties_applied' => null,
            'history' => null,
        ];

        $urbanityTier = $deso->urbanity_tier ?? 'semi_urban';
        $displayRadius = $this->getQueryRadius('display_radius', $urbanityTier);

        // Public tier: location + score + preview metadata (no actual values)
        if ($tier === DataTier::Public) {
            $preview = $this->buildPreview($deso->deso_code, $lat, $lng);

            return response()->json([
                'location' => [
                    'lat' => $lat,
                    'lng' => $lng,
                    'deso_code' => $deso->deso_code,
                    'kommun' => $deso->kommun_name,
                    'lan_code' => $deso->lan_code,
                    'area_km2' => $deso->area_km2,
                    'urbanity_tier' => $deso->urbanity_tier,
                ],
                'score' => $scoreData,
                'tier' => $tier->value,
                'display_radius' => $displayRadius,
                'preview' => $preview,
                'proximity' => null,
                'indicators' => [],
                'schools' => [],
                'pois' => [],
                'poi_summary' => [],
                'poi_categories' => [],
            ]);
        }

        // 5. Get indicator values for this DeSO (paid tiers) — all years for trends
        $activeIndicators = Indicator::where('is_active', true)->get()->keyBy('id');

        $allIndicatorValues = IndicatorValue::where('deso_code', $deso->deso_code)
            ->whereIn('indicator_id', $activeIndicators->keys())
            ->whereNotNull('raw_value')
            ->orderBy('year')
            ->get()
            ->groupBy('indicator_id');

        // 5b. Get composite score history for score sparkline
        $scoreHistory = CompositeScore::where('deso_code', $deso->deso_code)
            ->orderBy('year')
            ->get(['year', 'score']);

        // 6. Get nearby schools
        $schoolRadius = $this->getQueryRadius('school_query_radius', $urbanityTier);
        $schools = DB::select('
            SELECT s.name, s.type_of_schooling, s.operator_type, s.lat, s.lng,
                   ss.merit_value_17, ss.goal_achievement_pct,
                   ss.teacher_certification_pct, ss.student_count,
                   ST_Distance(
                       s.geom::geography,
                       ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                   ) as distance_m
            FROM schools s
            LEFT JOIN school_statistics ss ON ss.school_unit_code = s.school_unit_code
                AND ss.academic_year = (
                    SELECT MAX(academic_year) FROM school_statistics
                    WHERE school_unit_code = s.school_unit_code
                )
            WHERE s.status = \'active\'
              AND s.geom IS NOT NULL
              AND ST_DWithin(
                  s.geom::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  ?
              )
            ORDER BY distance_m
            LIMIT 10
        ', [$lng, $lat, $lng, $lat, $schoolRadius]);

        // 7. Get POIs — split into map markers (Tier 1) and sidebar counts (Tier 2)
        $poiRadius = $this->getQueryRadius('poi_query_radius', $urbanityTier);
        $poiCategories = PoiCategory::all();
        $mapSlugs = $poiCategories->where('show_on_map', true)->pluck('slug')->all();

        // Tier 1: Full details + coordinates for map markers (show_on_map categories only)
        $mapPois = [];
        if (! empty($mapSlugs)) {
            $mapPois = DB::select('
                SELECT p.name, p.category, p.lat, p.lng, p.subcategory,
                       ST_Distance(
                           p.geom::geography,
                           ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                       ) as distance_m
                FROM pois p
                JOIN poi_categories pc ON pc.slug = p.category
                WHERE p.status = \'active\'
                  AND pc.show_on_map = true
                  AND p.geom IS NOT NULL
                  AND ST_DWithin(
                      p.geom::geography,
                      ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                      ?
                  )
                ORDER BY distance_m
                LIMIT 100
            ', [$lng, $lat, $lng, $lat, $poiRadius]);
        }

        // Tier 2: Counts + nearest distance for sidebar (all categories)
        $poiSummary = DB::select('
            SELECT p.category,
                   COUNT(*) as count,
                   ROUND(MIN(ST_Distance(
                       p.geom::geography,
                       ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                   ))::numeric) as nearest_m
            FROM pois p
            WHERE p.status = \'active\'
              AND p.geom IS NOT NULL
              AND ST_DWithin(
                  p.geom::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  ?
              )
            GROUP BY p.category
            ORDER BY count DESC
        ', [$lng, $lat, $lng, $lat, $poiRadius]);

        // 8. Build POI category metadata for rendering
        $poiCategoryMap = $poiCategories->mapWithKeys(fn ($cat) => [
            $cat->slug => [
                'name' => $cat->name,
                'color' => $cat->color,
                'icon' => $cat->icon,
                'signal' => $cat->signal,
            ],
        ]);

        // Add score history for paid tiers
        if ($scoreHistory->count() >= 2) {
            $scoreData['history'] = [
                'years' => $scoreHistory->pluck('year')->values(),
                'scores' => $scoreHistory->pluck('score')->map(fn ($v) => round((float) $v, 1))->values(),
            ];
        }

        // Build indicators with trend data
        $indicatorsWithTrend = [];
        foreach ($allIndicatorValues as $indicatorId => $history) {
            $indicator = $activeIndicators->get($indicatorId);
            if (! $indicator) {
                continue;
            }

            $current = $history->last(); // latest year (ordered by year asc)

            $indicatorsWithTrend[] = $this->buildIndicatorWithTrend($indicator, $history, $current);
        }

        return response()->json([
            'location' => [
                'lat' => $lat,
                'lng' => $lng,
                'deso_code' => $deso->deso_code,
                'kommun' => $deso->kommun_name,
                'lan_code' => $deso->lan_code,
                'area_km2' => $deso->area_km2,
                'urbanity_tier' => $deso->urbanity_tier,
            ],
            'score' => $scoreData,
            'tier' => $tier->value,
            'display_radius' => $displayRadius,
            'proximity' => $proximity->toArray(),
            'indicators' => $indicatorsWithTrend,
            'schools' => collect($schools)->map(fn ($s) => [
                'name' => $s->name,
                'type' => $s->type_of_schooling,
                'operator_type' => $s->operator_type,
                'distance_m' => round((float) $s->distance_m),
                'merit_value' => $s->merit_value_17 ? (float) $s->merit_value_17 : null,
                'goal_achievement' => $s->goal_achievement_pct ? (float) $s->goal_achievement_pct : null,
                'teacher_certification' => $s->teacher_certification_pct ? (float) $s->teacher_certification_pct : null,
                'student_count' => $s->student_count,
                'lat' => (float) $s->lat,
                'lng' => (float) $s->lng,
            ]),
            'pois' => collect($mapPois)->map(fn ($p) => [
                'name' => $p->name,
                'category' => $p->category,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'distance_m' => round((float) $p->distance_m),
            ]),
            'poi_summary' => collect($poiSummary)->map(fn ($s) => [
                'category' => $s->category,
                'count' => (int) $s->count,
                'nearest_m' => (int) $s->nearest_m,
            ]),
            'poi_categories' => $poiCategoryMap,
        ]);
    }

    /**
     * Build preview metadata for public tier — category groups with free indicator values + locked counts.
     *
     * @return array<string, mixed>
     */
    private function buildPreview(string $desoCode, float $lat, float $lng): array
    {
        // Count actual data points for this DeSO
        $dataPointCount = IndicatorValue::where('deso_code', $desoCode)
            ->whereNotNull('raw_value')
            ->count();

        // Count distinct sources with data for this DeSO
        $sources = Indicator::where('is_active', true)
            ->whereHas('values', fn ($q) => $q->where('deso_code', $desoCode)->whereNotNull('raw_value'))
            ->distinct()
            ->pluck('source')
            ->values();

        // Nearby school count (within 2km)
        $nearbySchoolCount = (int) DB::selectOne('
            SELECT COUNT(*) as count FROM schools
            WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 2000)
              AND status = \'active\'
        ', [$lng, $lat])->count;

        // Build category sections with data scale stats
        $cachedStats = $this->previewStats->getStats();
        $proximityStats = $this->previewStats->proximityStats($lat, $lng);

        // Fetch free preview indicator values for this DeSO
        $freePreviewValues = IndicatorValue::query()
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->where('indicator_values.deso_code', $desoCode)
            ->where('indicators.is_active', true)
            ->where('indicators.is_free_preview', true)
            ->whereNotNull('indicator_values.raw_value')
            ->orderBy('indicators.display_order')
            ->select([
                'indicators.slug',
                'indicators.name',
                'indicators.unit',
                'indicators.direction',
                'indicator_values.raw_value',
                'indicator_values.normalized_value',
            ])
            ->get();

        // Index free values by slug for quick lookup
        $freeValuesBySlug = $freePreviewValues->keyBy('slug');

        // Slugs with actual data for this DeSO
        $desoCoveredSlugs = IndicatorValue::where('deso_code', $desoCode)
            ->whereNotNull('raw_value')
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->where('indicators.is_active', true)
            ->pluck('indicators.slug')
            ->toArray();

        $categories = [];
        foreach (config('indicator_categories') as $key => $catConfig) {
            $stats = $key === 'proximity' ? $proximityStats : ($cachedStats[$key] ?? null);

            $hasData = ! empty(array_intersect($catConfig['indicators'], $desoCoveredSlugs));

            // Build free indicators for this category (only those with data for this DeSO)
            $freeIndicators = [];
            foreach ($catConfig['indicators'] as $slug) {
                if ($freeValuesBySlug->has($slug)) {
                    $iv = $freeValuesBySlug->get($slug);
                    $freeIndicators[] = [
                        'slug' => $iv->slug,
                        'name' => $iv->name,
                        'raw_value' => round((float) $iv->raw_value, 1),
                        'percentile' => $iv->normalized_value !== null
                            ? (int) round((float) $iv->normalized_value * 100)
                            : null,
                        'unit' => $iv->unit,
                        'direction' => $iv->direction,
                    ];
                }
            }

            // Count total indicators with data in this category, then subtract free ones shown
            $coveredInCategory = count(array_intersect($catConfig['indicators'], $desoCoveredSlugs));
            $lockedCount = max(0, $coveredInCategory - count($freeIndicators));

            $category = [
                'slug' => $key,
                'label' => $catConfig['label'],
                'icon' => $catConfig['icon'],
                'stat_line' => $stats['stat_line'] ?? '',
                'indicator_count' => $coveredInCategory,
                'locked_count' => $lockedCount,
                'free_indicators' => $freeIndicators,
                'has_data' => $hasData,
            ];

            if ($key === 'proximity') {
                $category['poi_count'] = $proximityStats['poi_count'];
            }

            $categories[] = $category;
        }

        // Total indicator count: active indicators excluding contextual (weight > 0)
        $totalIndicatorCount = Indicator::where('is_active', true)
            ->where('weight', '>', 0)
            ->where('category', '!=', 'contextual')
            ->count();

        return [
            'data_point_count' => $dataPointCount,
            'source_count' => $sources->count(),
            'sources' => $sources,
            'categories' => $categories,
            'nearby_school_count' => $nearbySchoolCount,
            'cta_summary' => [
                'indicator_count' => $totalIndicatorCount,
                'insight_count' => 8,
                'poi_count' => $proximityStats['poi_count'],
            ],
        ];
    }

    private function blendScores(?float $areaScore, float $proximityScore): float
    {
        if ($areaScore === null) {
            // No area score: use proximity with default area of 50
            return round(50 * self::AREA_WEIGHT + $proximityScore * self::PROXIMITY_WEIGHT, 1);
        }

        return round($areaScore * self::AREA_WEIGHT + $proximityScore * self::PROXIMITY_WEIGHT, 1);
    }

    private function getQueryRadius(string $key, string $urbanityTier): int
    {
        $config = config("proximity.{$key}");

        if (is_array($config)) {
            return (int) ($config[$urbanityTier] ?? $config['semi_urban'] ?? 2000);
        }

        return (int) $config;
    }

    private function scoreLabel(float $score): string
    {
        foreach (config('score_colors.labels') as $label) {
            if ($score >= $label['min'] && $score <= $label['max']) {
                return $label['label_sv'];
            }
        }

        return 'Okänt';
    }

    /**
     * Build a single indicator array with historical trend data.
     *
     * @param  Collection<int, IndicatorValue>  $history
     * @return array<string, mixed>
     */
    private function buildIndicatorWithTrend(Indicator $indicator, Collection $history, IndicatorValue $current): array
    {
        $currentYear = (int) $current->year;
        $currentPercentile = round((float) $current->normalized_value * 100);

        $prevYear = $history->firstWhere('year', $currentYear - 1);
        $prevPercentile = $prevYear ? round((float) $prevYear->normalized_value * 100) : null;

        return [
            'slug' => $indicator->slug,
            'name' => $indicator->name,
            'raw_value' => (float) $current->raw_value,
            'normalized_value' => (float) $current->normalized_value,
            'unit' => $indicator->unit,
            'direction' => $indicator->direction,
            'category' => $indicator->category,
            'normalization_scope' => $indicator->normalization_scope,
            'trend' => [
                'years' => $history->pluck('year')->map(fn ($y) => (int) $y)->values(),
                'percentiles' => $history->pluck('normalized_value')
                    ->map(fn ($v) => (int) round((float) $v * 100))->values(),
                'raw_values' => $history->pluck('raw_value')
                    ->map(fn ($v) => (float) $v)->values(),
                'change_1y' => ($prevPercentile !== null)
                    ? (int) ($currentPercentile - $prevPercentile)
                    : null,
                'change_3y' => $this->computePercentileChange($history, $currentYear, 3),
                'change_5y' => $this->computePercentileChange($history, $currentYear, 5),
            ],
        ];
    }

    /**
     * @param  Collection<int, IndicatorValue>  $history
     */
    private function computePercentileChange(Collection $history, int $currentYear, int $span): ?int
    {
        $current = $history->firstWhere('year', $currentYear);
        $past = $history->firstWhere('year', $currentYear - $span);

        if (! $current || ! $past) {
            return null;
        }

        return (int) (round((float) $current->normalized_value * 100) - round((float) $past->normalized_value * 100));
    }
}
