<?php

namespace App\Http\Controllers;

use App\Enums\DataTier;
use App\Models\CompositeScore;
use App\Models\IndicatorValue;
use App\Models\PoiCategory;
use App\Services\DataTieringService;
use App\Services\ProximityScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    private const AREA_WEIGHT = 0.70;

    private const PROXIMITY_WEIGHT = 0.30;

    public function __construct(
        private DataTieringService $tiering,
        private ProximityScoreService $proximityService,
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

        // 3. Compute proximity score for this exact coordinate
        $proximity = $this->proximityService->score($lat, $lng);
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
        ] : [
            'value' => $proximityScore > 0 ? round($proximityScore * self::PROXIMITY_WEIGHT + 50 * self::AREA_WEIGHT, 1) : null,
            'area_score' => null,
            'proximity_score' => $proximityScore,
            'trend_1y' => null,
            'label' => null,
            'top_positive' => null,
            'top_negative' => null,
            'factor_scores' => null,
        ];

        // Public tier: location + score only, no detail data
        if ($tier === DataTier::Public) {
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
                'proximity' => null,
                'indicators' => [],
                'schools' => [],
                'pois' => [],
                'poi_categories' => [],
            ]);
        }

        // 5. Get indicator values for this DeSO (paid tiers)
        $indicators = IndicatorValue::where('deso_code', $deso->deso_code)
            ->whereHas('indicator', fn ($q) => $q->where('is_active', true))
            ->with('indicator')
            ->orderByDesc('year')
            ->get()
            ->unique('indicator_id');

        // 6. Get nearby schools (within 1.5km radius)
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
            WHERE s.status = \'AKTIV\'
              AND s.geom IS NOT NULL
              AND ST_DWithin(
                  s.geom::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  1500
              )
            ORDER BY distance_m
            LIMIT 10
        ', [$lng, $lat, $lng, $lat]);

        // 7. Get POIs within 3km radius
        $pois = DB::select('
            SELECT p.name, p.category, p.lat, p.lng, p.subcategory,
                   ST_Distance(
                       p.geom::geography,
                       ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                   ) as distance_m
            FROM pois p
            WHERE p.status = \'active\'
              AND p.geom IS NOT NULL
              AND ST_DWithin(
                  p.geom::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  3000
              )
            ORDER BY p.category, distance_m
        ', [$lng, $lat, $lng, $lat]);

        // 8. Get POI category metadata for rendering
        $poiCategories = PoiCategory::query()
            ->where('is_active', true)
            ->where('show_on_map', true)
            ->get()
            ->mapWithKeys(fn ($cat) => [
                $cat->slug => [
                    'name' => $cat->name,
                    'color' => $cat->color,
                    'icon' => $cat->icon,
                    'signal' => $cat->signal,
                ],
            ]);

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
            'proximity' => $proximity->toArray(),
            'indicators' => $indicators->map(fn ($iv) => [
                'slug' => $iv->indicator->slug,
                'name' => $iv->indicator->name,
                'raw_value' => (float) $iv->raw_value,
                'normalized_value' => (float) $iv->normalized_value,
                'unit' => $iv->indicator->unit,
                'direction' => $iv->indicator->direction,
                'category' => $iv->indicator->category,
                'normalization_scope' => $iv->indicator->normalization_scope,
            ])->values(),
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
            'pois' => collect($pois)->map(fn ($p) => [
                'name' => $p->name,
                'category' => $p->category,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'distance_m' => round((float) $p->distance_m),
            ]),
            'poi_categories' => $poiCategories,
        ]);
    }

    private function blendScores(?float $areaScore, float $proximityScore): float
    {
        if ($areaScore === null) {
            // No area score: use proximity with default area of 50
            return round(50 * self::AREA_WEIGHT + $proximityScore * self::PROXIMITY_WEIGHT, 1);
        }

        return round($areaScore * self::AREA_WEIGHT + $proximityScore * self::PROXIMITY_WEIGHT, 1);
    }

    private function scoreLabel(float $score): string
    {
        return match (true) {
            $score >= 80 => 'Starkt tillväxtområde',
            $score >= 60 => 'Stabilt / Positivt',
            $score >= 40 => 'Blandat',
            $score >= 20 => 'Förhöjd risk',
            default => 'Hög risk',
        };
    }
}
