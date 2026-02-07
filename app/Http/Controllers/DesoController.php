<?php

namespace App\Http\Controllers;

use App\Enums\DataTier;
use App\Models\DebtDisaggregationResult;
use App\Models\DesoArea;
use App\Models\DesoVulnerabilityMapping;
use App\Models\Indicator;
use App\Models\KronofogdenStatistic;
use App\Models\School;
use App\Models\ScoreVersion;
use App\Services\DataTieringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DesoController extends Controller
{
    public function __construct(
        private DataTieringService $tiering,
    ) {}

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
        $tenant = currentTenant();

        // Try tenant-specific published version first, then fall back to default (null tenant)
        $publishedVersion = null;

        if ($tenant) {
            $publishedVersion = ScoreVersion::query()
                ->where('year', $year)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'published')
                ->latest('published_at')
                ->first();
        }

        if (! $publishedVersion) {
            $publishedVersion = ScoreVersion::query()
                ->where('year', $year)
                ->whereNull('tenant_id')
                ->where('status', 'published')
                ->latest('published_at')
                ->first();
        }

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

    public function schools(string $desoCode, Request $request): JsonResponse
    {
        $user = $request->user();
        $tier = $this->tiering->resolveEffectiveTier($user, $desoCode);

        // Public and free tiers: school count only (handled in indicators endpoint)
        if ($tier->value < DataTier::Unlocked->value) {
            $count = School::where('deso_code', $desoCode)->where('status', 'active')->count();

            return response()->json([
                'school_count' => $count,
                'schools' => [],
                'tier' => $tier->value,
            ]);
        }

        $schools = School::query()
            ->where('deso_code', $desoCode)
            ->where('status', 'active')
            ->with('latestStatistics')
            ->get()
            ->map(function (School $school) use ($tier) {
                $data = [
                    'name' => $school->name,
                    'type' => $school->type_of_schooling,
                    'school_forms' => $school->school_forms ?? [],
                    'operator_type' => $school->operator_type,
                    'lat' => $school->lat ? (float) $school->lat : null,
                    'lng' => $school->lng ? (float) $school->lng : null,
                ];

                if ($tier === DataTier::Unlocked) {
                    // Band-level quality only
                    $data['quality_band'] = $this->schoolQualityBand($school->latestStatistics?->merit_value_17);
                } elseif ($tier->value >= DataTier::Subscriber->value) {
                    // Exact stats
                    $data['merit_value'] = $school->latestStatistics?->merit_value_17 ? (float) $school->latestStatistics->merit_value_17 : null;
                    $data['goal_achievement'] = $school->latestStatistics?->goal_achievement_pct ? (float) $school->latestStatistics->goal_achievement_pct : null;
                    $data['teacher_certification'] = $school->latestStatistics?->teacher_certification_pct ? (float) $school->latestStatistics->teacher_certification_pct : null;
                    $data['student_count'] = $school->latestStatistics?->student_count;
                }

                // Admin/Enterprise: include school_unit_code
                if ($tier->value >= DataTier::Admin->value) {
                    $data['school_unit_code'] = $school->school_unit_code;
                }

                return $data;
            });

        return response()->json([
            'school_count' => $schools->count(),
            'schools' => $schools,
            'tier' => $tier->value,
        ]);
    }

    public function crime(string $desoCode, Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $user = $request->user();
        $tier = $this->tiering->resolveEffectiveTier($user, $desoCode);

        // Public tier: no crime data
        if ($tier === DataTier::Public) {
            return response()->json([
                'deso_code' => $desoCode,
                'tier' => $tier->value,
                'locked' => true,
            ]);
        }

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

        // Free tier: band-level only
        if ($tier === DataTier::FreeAccount) {
            return response()->json([
                'deso_code' => $desoCode,
                'tier' => $tier->value,
                'locked' => false,
                'crime_band' => $this->tiering->percentileToBand(
                    $crimeIndicators->get('crime_total_rate')?->normalized_value
                        ? round((float) $crimeIndicators->get('crime_total_rate')->normalized_value * 100, 1)
                        : null,
                ),
                'safety_band' => $this->tiering->percentileToBand(
                    $crimeIndicators->get('perceived_safety')?->normalized_value
                        ? round((float) $crimeIndicators->get('perceived_safety')->normalized_value * 100, 1)
                        : null,
                ),
            ]);
        }

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

        // Unlocked tier: approximate values
        if ($tier === DataTier::Unlocked) {
            return response()->json([
                'deso_code' => $desoCode,
                'kommun_code' => $deso->kommun_code,
                'kommun_name' => $deso->kommun_name,
                'year' => $year,
                'tier' => $tier->value,
                'locked' => false,
                'estimated_rates' => [
                    'violent' => [
                        'rate_approx' => $this->tiering->roundRawValue(
                            $crimeIndicators->get('crime_violent_rate')?->raw_value ? (float) $crimeIndicators->get('crime_violent_rate')->raw_value : null,
                            '/100k',
                        ),
                        'percentile_band' => $this->tiering->percentileToWideBand(
                            $crimeIndicators->get('crime_violent_rate')?->normalized_value ? round((float) $crimeIndicators->get('crime_violent_rate')->normalized_value * 100, 1) : null,
                        ),
                    ],
                    'property' => [
                        'rate_approx' => $this->tiering->roundRawValue(
                            $crimeIndicators->get('crime_property_rate')?->raw_value ? (float) $crimeIndicators->get('crime_property_rate')->raw_value : null,
                            '/100k',
                        ),
                        'percentile_band' => $this->tiering->percentileToWideBand(
                            $crimeIndicators->get('crime_property_rate')?->normalized_value ? round((float) $crimeIndicators->get('crime_property_rate')->normalized_value * 100, 1) : null,
                        ),
                    ],
                ],
                'perceived_safety' => [
                    'approx' => $this->tiering->roundRawValue(
                        $crimeIndicators->get('perceived_safety')?->raw_value ? (float) $crimeIndicators->get('perceived_safety')->raw_value : null,
                        '%',
                    ),
                    'percentile_band' => $this->tiering->percentileToWideBand(
                        $crimeIndicators->get('perceived_safety')?->normalized_value ? round((float) $crimeIndicators->get('perceived_safety')->normalized_value * 100, 1) : null,
                    ),
                ],
                'vulnerability' => $vulnData,
            ]);
        }

        // Subscriber+ and Admin: full data
        // Kommun-level actual crime rates (for reference)
        $kommunCrime = DB::table('crime_statistics')
            ->where('municipality_code', $deso->kommun_code)
            ->where('year', $year)
            ->select('crime_category', 'reported_count', 'rate_per_100k')
            ->get()
            ->keyBy('crime_category');

        return response()->json([
            'deso_code' => $desoCode,
            'kommun_code' => $deso->kommun_code,
            'kommun_name' => $deso->kommun_name,
            'year' => $year,
            'tier' => $tier->value,
            'locked' => false,
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

    public function pois(string $desoCode, Request $request): JsonResponse
    {
        $user = $request->user();
        $tier = $this->tiering->resolveEffectiveTier($user, $desoCode);

        // Public tier: no POI data
        if ($tier === DataTier::Public) {
            return response()->json([
                'deso_code' => $desoCode,
                'tier' => $tier->value,
                'locked' => true,
            ]);
        }

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
            'tier' => $tier->value,
            'categories' => $grouped,
        ]);
    }

    public function indicators(string $desoCode, Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $user = $request->user();
        $tier = $this->tiering->resolveEffectiveTier($user, $desoCode);

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

        // Public tier: indicator names only (for blurred preview)
        if ($tier === DataTier::Public) {
            return response()->json([
                'deso_code' => $desoCode,
                'year' => $year,
                'tier' => $tier->value,
                'indicators' => $activeIndicators->map(fn (Indicator $ind) => [
                    'slug' => $ind->slug,
                    'name' => $ind->name,
                    'category' => $ind->category,
                    'locked' => true,
                ]),
            ]);
        }

        $indicatorValues = DB::table('indicator_values')
            ->where('deso_code', $desoCode)
            ->where('year', $year)
            ->whereIn('indicator_id', $activeIndicators->pluck('id'))
            ->get()
            ->keyBy('indicator_id');

        // Trends — only for free+ tiers
        $trends = collect();
        if ($tier->value >= DataTier::FreeAccount->value) {
            $trends = DB::table('indicator_trends')
                ->where('deso_code', $desoCode)
                ->whereIn('indicator_id', $activeIndicators->pluck('id'))
                ->orderByDesc('end_year')
                ->get()
                ->unique('indicator_id')
                ->keyBy('indicator_id');
        }

        // Historical values — only for subscriber+
        $historicalValues = collect();
        if ($tier->value >= DataTier::Subscriber->value) {
            $historicalValues = DB::table('indicator_values')
                ->where('deso_code', $desoCode)
                ->whereIn('indicator_id', $activeIndicators->pluck('id'))
                ->whereNotNull('raw_value')
                ->orderBy('year')
                ->get()
                ->groupBy('indicator_id');
        }

        // Admin: compute rank and coverage
        $ranks = collect();
        $coverageCounts = collect();
        if ($tier === DataTier::Admin) {
            foreach ($activeIndicators as $ind) {
                $iv = $indicatorValues->get($ind->id);
                if ($iv && $iv->normalized_value !== null) {
                    $rank = DB::table('indicator_values')
                        ->where('indicator_id', $ind->id)
                        ->where('year', $year)
                        ->whereNotNull('normalized_value')
                        ->where('normalized_value', '>', $iv->normalized_value)
                        ->count() + 1;
                    $ranks[$ind->id] = $rank;
                }

                $coverageCounts[$ind->id] = DB::table('indicator_values')
                    ->where('indicator_id', $ind->id)
                    ->where('year', $year)
                    ->whereNotNull('raw_value')
                    ->count();
            }
        }

        // Get composite score for admin weighted contribution calculation
        $compositeScore = null;
        if ($tier === DataTier::Admin) {
            $compositeScore = DB::table('composite_scores')
                ->where('deso_code', $desoCode)
                ->where('year', $year)
                ->first();
        }

        $totalDesos = 6160; // constant for rank_total

        $indicators = $activeIndicators->map(function (Indicator $ind) use ($indicatorValues, $trends, $historicalValues, $tier, $ranks, $coverageCounts, $totalDesos) {
            $iv = $indicatorValues->get($ind->id);
            $trend = $trends->get($ind->id);

            $history = ($historicalValues->get($ind->id) ?? collect())
                ->map(fn ($row) => [
                    'year' => $row->year,
                    'value' => $row->raw_value !== null ? round((float) $row->raw_value, 2) : null,
                ])
                ->values()
                ->all();

            $data = [
                'slug' => $ind->slug,
                'name' => $ind->name,
                'category' => $ind->category,
                'raw_value' => $iv?->raw_value !== null ? round((float) $iv->raw_value, 4) : null,
                'normalized_value' => $iv?->normalized_value !== null ? round((float) $iv->normalized_value, 6) : null,
                'unit' => $ind->unit,
                'direction' => $ind->direction,
                'normalization_scope' => $ind->normalization_scope,
                'description_short' => $ind->description_short,
                'description_long' => $ind->description_long,
                'methodology_note' => $ind->methodology_note,
                'national_context' => $ind->national_context,
                'source_name' => $ind->source_name,
                'source_url' => $ind->source_url,
                'data_vintage' => $ind->data_vintage,
                'data_last_ingested_at' => $ind->last_ingested_at?->toIso8601String(),
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

            // Admin extras
            if ($tier === DataTier::Admin) {
                $normalizedVal = $iv?->normalized_value !== null ? (float) $iv->normalized_value : null;
                $directedVal = $normalizedVal;
                if ($ind->direction === 'negative' && $normalizedVal !== null) {
                    $directedVal = 1.0 - $normalizedVal;
                }
                $weightedContribution = ($directedVal !== null && $ind->weight)
                    ? round($directedVal * (float) $ind->weight * 100, 2)
                    : null;

                $data['weight'] = (float) $ind->weight;
                $data['weighted_contribution'] = $weightedContribution;
                $data['rank'] = $ranks[$ind->id] ?? null;
                $data['rank_total'] = $coverageCounts[$ind->id] ?? $totalDesos;
                $data['normalization_method'] = $ind->normalization;
                $data['coverage_count'] = $coverageCounts[$ind->id] ?? null;
                $data['coverage_total'] = $totalDesos;
                $data['source_api_path'] = $ind->source_api_path;
                $data['source_field_code'] = $ind->source_field_code;
                $data['data_quality_notes'] = $ind->data_quality_notes;
                $data['admin_notes'] = $ind->admin_notes;
            }

            return $data;
        });

        // Apply tier transformation
        $transformedIndicators = $this->tiering->transformIndicators($indicators, $tier);

        $indicatorsWithTrends = $indicators->filter(fn ($i) => $i['trend'] !== null && ($i['trend']['direction'] ?? null) !== 'insufficient')->count();
        $trendEntry = $trends->first();

        $response = [
            'deso_code' => $desoCode,
            'year' => $year,
            'tier' => $tier->value,
            'indicators' => $transformedIndicators->values(),
            'trend_eligible' => $deso->trend_eligible,
            'trend_meta' => [
                'eligible' => $deso->trend_eligible,
                'reason' => $deso->trend_eligible ? null : 'Area boundaries changed in 2025 revision',
                'indicators_with_trends' => $indicatorsWithTrends,
                'indicators_total' => $activeIndicators->count(),
                'period' => $trendEntry ? $trendEntry->base_year.'–'.$trendEntry->end_year : null,
            ],
        ];

        // Admin: include score breakdown
        if ($tier === DataTier::Admin && $compositeScore) {
            $response['score_breakdown'] = [
                'score' => round((float) $compositeScore->score, 2),
                'factor_scores' => json_decode($compositeScore->factor_scores, true),
                'top_positive' => json_decode($compositeScore->top_positive, true),
                'top_negative' => json_decode($compositeScore->top_negative, true),
            ];
        }

        // Unlock options for free tier
        if ($tier === DataTier::FreeAccount) {
            $desoArea = DB::table('deso_areas')
                ->where('deso_code', $desoCode)
                ->select('kommun_code', 'kommun_name')
                ->first();

            if ($desoArea) {
                $response['unlock_options'] = [
                    'deso' => ['code' => $desoCode, 'price' => 7900],
                    'kommun' => ['code' => $desoArea->kommun_code, 'name' => $desoArea->kommun_name, 'price' => 19900],
                ];
            }
        }

        return response()->json($response);
    }

    public function financial(string $desoCode, Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $user = $request->user();
        $tier = $this->tiering->resolveEffectiveTier($user, $desoCode);

        // Public tier: no financial data
        if ($tier === DataTier::Public) {
            return response()->json([
                'deso_code' => $desoCode,
                'tier' => $tier->value,
                'locked' => true,
            ]);
        }

        $disaggResult = DebtDisaggregationResult::query()
            ->where('deso_code', $desoCode)
            ->where('year', $year)
            ->first();

        if (! $disaggResult) {
            $disaggResult = DebtDisaggregationResult::query()
                ->where('deso_code', $desoCode)
                ->latest('year')
                ->first();
        }

        // Free tier: band only
        if ($tier === DataTier::FreeAccount) {
            $debtIndicator = DB::table('indicator_values')
                ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
                ->where('indicators.slug', 'debt_rate_pct')
                ->where('indicator_values.deso_code', $desoCode)
                ->where('indicator_values.year', $year)
                ->select('indicator_values.normalized_value')
                ->first();

            return response()->json([
                'deso_code' => $desoCode,
                'tier' => $tier->value,
                'locked' => false,
                'debt_band' => $this->tiering->percentileToBand(
                    $debtIndicator?->normalized_value ? round((float) $debtIndicator->normalized_value * 100, 1) : null,
                ),
            ]);
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

        // Unlocked tier: approximate values
        if ($tier === DataTier::Unlocked) {
            return response()->json([
                'deso_code' => $desoCode,
                'year' => $disaggResult?->year,
                'tier' => $tier->value,
                'locked' => false,
                'estimated_debt_rate_approx' => $this->tiering->roundRawValue(
                    $disaggResult?->estimated_debt_rate ? (float) $disaggResult->estimated_debt_rate : null,
                    '%',
                ),
                'estimated_eviction_rate_approx' => $this->tiering->roundRawValue(
                    $disaggResult?->estimated_eviction_rate ? (float) $disaggResult->estimated_eviction_rate : null,
                    '/100k',
                ),
                'kommun_name' => $kommunStats?->municipality_name,
                'is_high_distress' => ($disaggResult?->estimated_debt_rate ?? 0) > (($nationalAvg ?? 0) * 2),
                'is_estimated' => true,
            ]);
        }

        // Subscriber+ and Admin: full data
        return response()->json([
            'deso_code' => $desoCode,
            'year' => $disaggResult?->year,
            'tier' => $tier->value,
            'locked' => false,
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

    private function schoolQualityBand(?float $meritValue): ?string
    {
        if ($meritValue === null) {
            return null;
        }

        return match (true) {
            $meritValue >= 250 => 'very_high',
            $meritValue >= 220 => 'high',
            $meritValue >= 190 => 'average',
            $meritValue >= 160 => 'low',
            default => 'very_low',
        };
    }
}
