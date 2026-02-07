<?php

namespace App\Http\Controllers;

use App\Models\DebtDisaggregationResult;
use App\Models\DesoArea;
use App\Models\DesoVulnerabilityMapping;
use App\Models\Indicator;
use App\Models\KronofogdenStatistic;
use App\Models\School;
use App\Models\ScoreVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DesoController extends Controller
{
    public function geojson(): JsonResponse|BinaryFileResponse
    {
        $staticPath = public_path('data/deso.geojson');

        if (file_exists($staticPath)) {
            return response()->file($staticPath, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        // Fallback: generate from DB if static file doesn't exist
        $features = DB::select('
            SELECT
                deso_code,
                deso_name,
                kommun_code,
                kommun_name,
                lan_code,
                lan_name,
                area_km2,
                ST_AsGeoJSON(ST_Buffer(geom, 0.00005)) as geometry
            FROM deso_areas
            WHERE geom IS NOT NULL
        ');

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => collect($features)->map(fn ($f) => [
                'type' => 'Feature',
                'geometry' => json_decode($f->geometry),
                'properties' => [
                    'deso_code' => $f->deso_code,
                    'deso_name' => $f->deso_name,
                    'kommun_code' => $f->kommun_code,
                    'kommun_name' => $f->kommun_name,
                    'lan_code' => $f->lan_code,
                    'lan_name' => $f->lan_name,
                    'area_km2' => $f->area_km2,
                ],
            ])->all(),
        ];

        return response()->json($geojson)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function scores(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        // Serve scores from the latest published version, falling back to any scores for the year
        $publishedVersion = ScoreVersion::query()
            ->where('year', $year)
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        $query = DB::table('composite_scores')
            ->leftJoin('deso_areas', 'deso_areas.deso_code', '=', 'composite_scores.deso_code')
            ->where('composite_scores.year', $year)
            ->select('composite_scores.deso_code', 'score', 'trend_1y', 'factor_scores', 'top_positive', 'top_negative', 'deso_areas.urbanity_tier');

        if ($publishedVersion) {
            $query->where('score_version_id', $publishedVersion->id);
        } else {
            // Fallback: serve latest scores (backward compatible with pre-versioning data)
            $query->whereNull('score_version_id');
        }

        $scores = $query->get()->keyBy('deso_code');

        return response()->json($scores)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function schools(string $desoCode): JsonResponse
    {
        $schools = School::query()
            ->where('deso_code', $desoCode)
            ->where('status', 'active')
            ->with('latestStatistics')
            ->get()
            ->map(fn (School $school) => [
                'school_unit_code' => $school->school_unit_code,
                'name' => $school->name,
                'type' => $school->type_of_schooling,
                'operator_type' => $school->operator_type,
                'lat' => $school->lat ? (float) $school->lat : null,
                'lng' => $school->lng ? (float) $school->lng : null,
                'merit_value' => $school->latestStatistics?->merit_value_17 ? (float) $school->latestStatistics->merit_value_17 : null,
                'goal_achievement' => $school->latestStatistics?->goal_achievement_pct ? (float) $school->latestStatistics->goal_achievement_pct : null,
                'teacher_certification' => $school->latestStatistics?->teacher_certification_pct ? (float) $school->latestStatistics->teacher_certification_pct : null,
                'student_count' => $school->latestStatistics?->student_count,
            ]);

        return response()->json($schools);
    }

    public function crime(string $desoCode, Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        // Get kommun code for this DeSO
        $deso = DB::table('deso_areas')
            ->where('deso_code', $desoCode)
            ->select('kommun_code', 'kommun_name', 'lan_code')
            ->first();

        if (! $deso) {
            return response()->json(['error' => 'DeSO not found'], 404);
        }

        // Estimated DeSO-level crime rates from indicator values
        $crimeIndicators = DB::table('indicator_values')
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->whereIn('indicators.slug', ['crime_violent_rate', 'crime_property_rate', 'crime_total_rate', 'perceived_safety'])
            ->where('indicator_values.deso_code', $desoCode)
            ->where('indicator_values.year', $year)
            ->select('indicators.slug', 'indicator_values.raw_value', 'indicator_values.normalized_value')
            ->get()
            ->keyBy('slug');

        // Kommun-level actual crime rates (for reference)
        $kommunCrime = DB::table('crime_statistics')
            ->where('municipality_code', $deso->kommun_code)
            ->where('year', $year)
            ->select('crime_category', 'reported_count', 'rate_per_100k')
            ->get()
            ->keyBy('crime_category');

        // Vulnerability area info
        $vulnerability = DesoVulnerabilityMapping::query()
            ->where('deso_code', $desoCode)
            ->where('overlap_fraction', '>=', 0.25)
            ->with('vulnerabilityArea')
            ->orderByRaw("CASE WHEN tier = 'sarskilt_utsatt' THEN 0 ELSE 1 END")
            ->first();

        $vulnData = null;
        if ($vulnerability) {
            $area = $vulnerability->vulnerabilityArea;
            $vulnData = [
                'name' => $area->name,
                'tier' => $vulnerability->tier,
                'tier_label' => $vulnerability->tier === 'sarskilt_utsatt' ? 'Särskilt utsatt område' : 'Utsatt område',
                'overlap_fraction' => (float) $vulnerability->overlap_fraction,
                'assessment_year' => $area->assessment_year,
                'police_region' => $area->police_region,
            ];
        }

        return response()->json([
            'deso_code' => $desoCode,
            'kommun_code' => $deso->kommun_code,
            'kommun_name' => $deso->kommun_name,
            'year' => $year,
            'estimated_rates' => [
                'violent' => [
                    'rate' => $crimeIndicators->get('crime_violent_rate')?->raw_value ? round((float) $crimeIndicators->get('crime_violent_rate')->raw_value, 1) : null,
                    'percentile' => $crimeIndicators->get('crime_violent_rate')?->normalized_value ? round((float) $crimeIndicators->get('crime_violent_rate')->normalized_value * 100, 1) : null,
                ],
                'property' => [
                    'rate' => $crimeIndicators->get('crime_property_rate')?->raw_value ? round((float) $crimeIndicators->get('crime_property_rate')->raw_value, 1) : null,
                    'percentile' => $crimeIndicators->get('crime_property_rate')?->normalized_value ? round((float) $crimeIndicators->get('crime_property_rate')->normalized_value * 100, 1) : null,
                ],
                'total' => [
                    'rate' => $crimeIndicators->get('crime_total_rate')?->raw_value ? round((float) $crimeIndicators->get('crime_total_rate')->raw_value, 1) : null,
                    'percentile' => $crimeIndicators->get('crime_total_rate')?->normalized_value ? round((float) $crimeIndicators->get('crime_total_rate')->normalized_value * 100, 1) : null,
                ],
            ],
            'perceived_safety' => [
                'percent_safe' => $crimeIndicators->get('perceived_safety')?->raw_value ? round((float) $crimeIndicators->get('perceived_safety')->raw_value, 1) : null,
                'percentile' => $crimeIndicators->get('perceived_safety')?->normalized_value ? round((float) $crimeIndicators->get('perceived_safety')->normalized_value * 100, 1) : null,
            ],
            'kommun_actual_rates' => [
                'total' => $kommunCrime->get('crime_total')?->rate_per_100k ? round((float) $kommunCrime->get('crime_total')->rate_per_100k, 1) : null,
                'person' => $kommunCrime->get('crime_person')?->rate_per_100k ? round((float) $kommunCrime->get('crime_person')->rate_per_100k, 1) : null,
                'theft' => $kommunCrime->get('crime_theft')?->rate_per_100k ? round((float) $kommunCrime->get('crime_theft')->rate_per_100k, 1) : null,
            ],
            'vulnerability' => $vulnData,
        ]);
    }

    public function pois(string $desoCode): JsonResponse
    {
        $deso = DB::table('deso_areas')
            ->where('deso_code', $desoCode)
            ->select('deso_code')
            ->first();

        if (! $deso) {
            return response()->json(['error' => 'DeSO not found'], 404);
        }

        // POIs within the DeSO + nearby (within catchment) grouped by category
        $pois = DB::select("
            SELECT
                p.id, p.name, p.category, p.subcategory,
                p.lat, p.lng, p.source,
                p.deso_code = ? AS is_within_deso
            FROM pois p
            WHERE p.status = 'active'
              AND p.geom IS NOT NULL
              AND ST_DWithin(
                  p.geom::geography,
                  (SELECT ST_Centroid(geom)::geography FROM deso_areas WHERE deso_code = ?),
                  3000
              )
            ORDER BY p.category, p.name
        ", [$desoCode, $desoCode]);

        // Group by category
        $grouped = collect($pois)->groupBy('category')->map(function ($items, $category) {
            return [
                'category' => $category,
                'count' => $items->count(),
                'within_deso' => $items->where('is_within_deso', true)->count(),
                'items' => $items->map(fn ($p) => [
                    'name' => $p->name,
                    'lat' => (float) $p->lat,
                    'lng' => (float) $p->lng,
                    'source' => $p->source,
                    'within_deso' => (bool) $p->is_within_deso,
                ])->values()->all(),
            ];
        })->values();

        return response()->json([
            'deso_code' => $desoCode,
            'categories' => $grouped,
        ]);
    }

    public function indicators(string $desoCode, Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $deso = DesoArea::query()
            ->where('deso_code', $desoCode)
            ->first(['deso_code', 'trend_eligible']);

        if (! $deso) {
            return response()->json(['error' => 'DeSO not found'], 404);
        }

        $activeIndicators = Indicator::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $indicatorValues = DB::table('indicator_values')
            ->where('deso_code', $desoCode)
            ->where('year', $year)
            ->whereIn('indicator_id', $activeIndicators->pluck('id'))
            ->get()
            ->keyBy('indicator_id');

        // Get trends for this DeSO
        $trends = DB::table('indicator_trends')
            ->where('deso_code', $desoCode)
            ->whereIn('indicator_id', $activeIndicators->pluck('id'))
            ->orderByDesc('end_year')
            ->get()
            ->unique('indicator_id')
            ->keyBy('indicator_id');

        // Get historical values for tooltip (all years for this DeSO)
        $historicalValues = DB::table('indicator_values')
            ->where('deso_code', $desoCode)
            ->whereIn('indicator_id', $activeIndicators->pluck('id'))
            ->whereNotNull('raw_value')
            ->orderBy('year')
            ->get()
            ->groupBy('indicator_id');

        $indicators = $activeIndicators->map(function (Indicator $ind) use ($indicatorValues, $trends, $historicalValues) {
            $iv = $indicatorValues->get($ind->id);
            $trend = $trends->get($ind->id);

            $history = ($historicalValues->get($ind->id) ?? collect())
                ->map(fn ($row) => [
                    'year' => $row->year,
                    'value' => $row->raw_value !== null ? round((float) $row->raw_value, 2) : null,
                ])
                ->values()
                ->all();

            return [
                'slug' => $ind->slug,
                'name' => $ind->name,
                'raw_value' => $iv?->raw_value !== null ? round((float) $iv->raw_value, 4) : null,
                'normalized_value' => $iv?->normalized_value !== null ? round((float) $iv->normalized_value, 6) : null,
                'unit' => $ind->unit,
                'direction' => $ind->direction,
                'normalization_scope' => $ind->normalization_scope,
                'trend' => $trend ? [
                    'direction' => $trend->direction,
                    'percent_change' => $trend->percent_change !== null ? round((float) $trend->percent_change, 2) : null,
                    'absolute_change' => $trend->absolute_change !== null ? round((float) $trend->absolute_change, 2) : null,
                    'base_year' => $trend->base_year,
                    'end_year' => $trend->end_year,
                    'data_points' => $trend->data_points,
                    'confidence' => $trend->confidence !== null ? round((float) $trend->confidence, 2) : null,
                ] : null,
                'history' => $history,
            ];
        });

        $indicatorsWithTrends = $indicators->filter(fn ($i) => $i['trend'] !== null && $i['trend']['direction'] !== 'insufficient')->count();
        $trendEntry = $trends->first();

        return response()->json([
            'deso_code' => $desoCode,
            'year' => $year,
            'indicators' => $indicators->values(),
            'trend_eligible' => $deso->trend_eligible,
            'trend_meta' => [
                'eligible' => $deso->trend_eligible,
                'reason' => $deso->trend_eligible ? null : 'Area boundaries changed in 2025 revision',
                'indicators_with_trends' => $indicatorsWithTrends,
                'indicators_total' => $activeIndicators->count(),
                'period' => $trendEntry ? $trendEntry->base_year.'–'.$trendEntry->end_year : null,
            ],
        ]);
    }

    public function financial(string $desoCode, Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $disaggResult = DebtDisaggregationResult::query()
            ->where('deso_code', $desoCode)
            ->where('year', $year)
            ->first();

        if (! $disaggResult) {
            // Try latest available year
            $disaggResult = DebtDisaggregationResult::query()
                ->where('deso_code', $desoCode)
                ->latest('year')
                ->first();
        }

        $kommunStats = null;
        if ($disaggResult) {
            $kommunStats = KronofogdenStatistic::query()
                ->where('municipality_code', $disaggResult->municipality_code)
                ->where('year', $disaggResult->year)
                ->first();
        }

        $nationalAvg = KronofogdenStatistic::query()
            ->where('year', $disaggResult?->year ?? $year)
            ->avg('indebted_pct');

        return response()->json([
            'deso_code' => $desoCode,
            'year' => $disaggResult?->year,
            'estimated_debt_rate' => $disaggResult?->estimated_debt_rate ? round((float) $disaggResult->estimated_debt_rate, 2) : null,
            'estimated_eviction_rate' => $disaggResult?->estimated_eviction_rate ? round((float) $disaggResult->estimated_eviction_rate, 1) : null,
            'kommun_actual_rate' => $kommunStats?->indebted_pct ? round((float) $kommunStats->indebted_pct, 2) : null,
            'kommun_name' => $kommunStats?->municipality_name,
            'kommun_median_debt' => $kommunStats?->median_debt_sek ? round((float) $kommunStats->median_debt_sek, 0) : null,
            'kommun_eviction_rate' => $kommunStats?->eviction_rate_per_100k ? round((float) $kommunStats->eviction_rate_per_100k, 1) : null,
            'national_avg_rate' => $nationalAvg ? round((float) $nationalAvg, 2) : null,
            'is_high_distress' => ($disaggResult?->estimated_debt_rate ?? 0) > (($nationalAvg ?? 0) * 2),
            'is_estimated' => true,
        ]);
    }
}
