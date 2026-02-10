<?php

namespace App\Services;

use App\DataTransferObjects\ProximityFactor;
use App\DataTransferObjects\ProximityResult;
use App\Models\PoiCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pin-level proximity scoring (Role B).
 *
 * POIs serve two distinct roles in the scoring system:
 *
 * Role A — Area Density Indicators (DeSO-level):
 *   "This DeSO has 15.1 restaurants per km²" → stored as indicator_values
 *   (e.g. grocery_density, transit_stop_density). Pre-computed per DeSO via
 *   aggregate:poi-indicators. Same value for all pins in a DeSO. Feeds the
 *   Area Score (70% of composite).
 *
 * Role B — Proximity Scores (Pin-level, THIS service):
 *   "The nearest grocery store is 340m from your pin" → computed on-the-fly
 *   per coordinate via PostGIS distance queries. Different for every address,
 *   even within the same DeSO. Feeds the Proximity Score (30% of composite).
 *
 * No double counting: area density and pin proximity measure different things
 * (neighborhood character vs. personal convenience) and contribute to separate
 * score layers with independent weight budgets.
 *
 * When isochrone is enabled, scoring uses actual walking/driving times from
 * Valhalla instead of crow-flies distances. Falls back to radius when
 * Valhalla is unavailable.
 */
class ProximityScoreService
{
    public function __construct(
        private SafetyScoreService $safety,
        private IsochroneService $isochrone,
    ) {}

    /**
     * Compute proximity scores with grid-cell caching (~100m resolution).
     * Repeat clicks within the same ~100m grid cell return cached results instantly.
     */
    public function scoreCached(float $lat, float $lng): ProximityResult
    {
        $gridLat = round($lat, 3);
        $gridLng = round($lng, 3);
        $cacheKey = "proximity:{$gridLat},{$gridLng}";

        return Cache::remember($cacheKey, 3600, fn () => $this->score($lat, $lng));
    }

    /**
     * Compute proximity scores for a specific coordinate.
     * Returns a 0-100 composite score and breakdown of each proximity factor.
     *
     * This runs on every pin drop — must be fast (<500ms with isochrone, <200ms with radius).
     */
    public function score(float $lat, float $lng): ProximityResult
    {
        // Resolve DeSO for the pin to get safety context and urbanity tier
        $deso = DB::selectOne('
            SELECT deso_code, urbanity_tier FROM deso_areas
            WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ', [$lng, $lat]);

        $urbanityTier = $deso->urbanity_tier ?? 'semi_urban';
        $safetyScore = $deso
            ? $this->safety->forDeso($deso->deso_code, now()->year - 1)
            : 0.5;

        $settings = $this->getCategorySettings();

        // Try isochrone-based scoring
        if (config('proximity.isochrone.enabled')) {
            $costing = config("proximity.isochrone.costing.{$urbanityTier}", 'pedestrian');
            $maxMinutes = (int) config("proximity.isochrone.scoring_contour.{$urbanityTier}", 15);

            $boundaryWkt = $this->isochrone->outermostPolygonWkt($lat, $lng, $costing, $maxMinutes);

            if ($boundaryWkt) {
                return $this->scoreWithIsochrone(
                    $lat, $lng, $boundaryWkt, $costing,
                    $urbanityTier, $safetyScore, $settings,
                );
            }
        }

        // Fallback: radius-based scoring (current behavior)
        return $this->scoreWithRadius($lat, $lng, $urbanityTier, $safetyScore, $settings);
    }

    // ─── Isochrone-based scoring ─────────────────────────────────────────

    private function scoreWithIsochrone(
        float $lat,
        float $lng,
        string $boundaryWkt,
        string $costing,
        string $urbanityTier,
        float $safetyScore,
        Collection $settings,
    ): ProximityResult {
        // 1. Query ALL POIs + schools + transit inside the isochrone boundary
        $schools = $this->querySchoolsInPolygon($lng, $lat, $boundaryWkt);
        $pois = $this->queryPoisInPolygon($lng, $lat, $boundaryWkt);
        $transitStops = $this->queryTransitInPolygon($lng, $lat, $boundaryWkt);

        // 2. Collect all target coordinates for matrix call
        $targets = [];
        $targetMap = [];

        foreach ($schools as $i => $s) {
            $targets[] = ['lat' => (float) $s->lat, 'lng' => (float) $s->lng];
            $targetMap[] = ['type' => 'school', 'index' => $i];
        }
        foreach ($pois as $i => $p) {
            $targets[] = ['lat' => (float) $p->lat, 'lng' => (float) $p->lng];
            $targetMap[] = ['type' => 'poi', 'index' => $i];
        }
        foreach ($transitStops as $i => $t) {
            $targets[] = ['lat' => (float) $t->lat, 'lng' => (float) $t->lng];
            $targetMap[] = ['type' => 'transit', 'index' => $i];
        }

        // 3. Get actual travel times in matrix call(s)
        $travelTimes = $this->isochrone->travelTimes($lat, $lng, $targets, $costing);

        // 4. Attach travel times back to the source objects
        foreach ($travelTimes as $idx => $seconds) {
            if (! isset($targetMap[$idx])) {
                continue;
            }
            $map = $targetMap[$idx];
            match ($map['type']) {
                'school' => $schools[$map['index']]->travel_seconds = $seconds,
                'poi' => $pois[$map['index']]->travel_seconds = $seconds,
                'transit' => $transitStops[$map['index']]->travel_seconds = $seconds,
            };
        }

        // 5. Score each category using travel time
        return new ProximityResult(
            school: $this->scoreSchoolByTime($schools, $urbanityTier, $safetyScore, $settings, $costing),
            greenSpace: $this->scoreCategoryByTime($pois, 'green_space', ['park', 'nature_reserve'], $urbanityTier, $safetyScore, $settings),
            transit: $this->scoreTransitByTime($transitStops, $urbanityTier, $safetyScore, $settings, $costing),
            grocery: $this->scoreCategoryByTime($pois, 'grocery', ['grocery'], $urbanityTier, $safetyScore, $settings),
            negativePoi: $this->scoreNegativeByTime($pois, $urbanityTier),
            positivePoi: $this->scorePositiveByTime($pois, $urbanityTier, $safetyScore, $settings),
            safetyScore: $safetyScore,
            urbanityTier: $urbanityTier,
        );
    }

    /**
     * @param  array<int, object>  $schools
     */
    private function scoreSchoolByTime(array $schools, string $urbanityTier, float $safetyScore, Collection $settings, string $costing): ProximityFactor
    {
        $maxMinutes = $this->getMaxMinutes('school', $urbanityTier);
        $sensitivity = (float) ($settings->get('school_grundskola')?->safety_sensitivity ?? 0.80);

        // Filter to grundskola only
        $grundskolor = array_values(array_filter($schools, fn ($s) => stripos($s->type_of_schooling ?? '', 'grundskola') !== false));

        $schoolDetails = collect($grundskolor)->map(fn ($s) => [
            'name' => $s->name,
            'type' => $s->type_of_schooling,
            'operator' => $s->operator_type,
            'distance_m' => (int) round($s->distance_m),
            'travel_seconds' => $s->travel_seconds ?? null,
            'travel_minutes' => ($s->travel_seconds ?? null) !== null ? round($s->travel_seconds / 60, 1) : null,
            'merit_value' => $s->merit_value_17 !== null ? (float) $s->merit_value_17 : null,
            'goal_achievement' => $s->goal_achievement_pct !== null ? (float) $s->goal_achievement_pct : null,
            'teacher_certification' => $s->teacher_certification_pct !== null ? (float) $s->teacher_certification_pct : null,
            'student_count' => $s->student_count,
        ])->values()->all();

        if (empty($grundskolor)) {
            return new ProximityFactor(
                slug: 'prox_school',
                score: 0,
                details: [
                    'message' => 'No grundskola within isochrone',
                    'scoring_mode' => 'isochrone',
                    'costing' => $costing,
                    'schools' => [],
                ],
            );
        }

        $bestScore = 0;
        $bestSchool = null;
        $bestEffectiveMinutes = null;

        foreach ($grundskolor as $school) {
            if ($school->merit_value_17 === null) {
                continue;
            }

            $qualityNorm = min(1.0, max(0, ($school->merit_value_17 - 150) / 130));
            $decay = $this->decayWithSafetyTime($school->travel_seconds ?? null, $maxMinutes, $safetyScore, $sensitivity);
            $combined = $qualityNorm * $decay;

            if ($combined > $bestScore) {
                $bestScore = $combined;
                $bestSchool = $school;
                $riskPenalty = (1.0 - $safetyScore) * $sensitivity;
                $travelMin = ($school->travel_seconds ?? 0) / 60.0;
                $bestEffectiveMinutes = $travelMin * (1.0 + $riskPenalty);
            }
        }

        if ($bestSchool === null) {
            $nearest = $grundskolor[0];
            $decay = $this->decayWithSafetyTime($nearest->travel_seconds ?? null, $maxMinutes, $safetyScore, $sensitivity);

            return new ProximityFactor(
                slug: 'prox_school',
                score: (int) round($decay * 50),
                details: [
                    'nearest_school' => $nearest->name,
                    'nearest_distance_m' => (int) round($nearest->distance_m),
                    'travel_seconds' => $nearest->travel_seconds ?? null,
                    'travel_minutes' => ($nearest->travel_seconds ?? null) !== null ? round($nearest->travel_seconds / 60, 1) : null,
                    'scoring_mode' => 'isochrone',
                    'costing' => $costing,
                    'schools_found' => count($grundskolor),
                    'merit_data' => false,
                    'schools' => $schoolDetails,
                ],
            );
        }

        return new ProximityFactor(
            slug: 'prox_school',
            score: (int) round($bestScore * 100),
            details: [
                'nearest_school' => $bestSchool->name,
                'nearest_merit' => (float) $bestSchool->merit_value_17,
                'nearest_distance_m' => (int) round($bestSchool->distance_m),
                'travel_seconds' => $bestSchool->travel_seconds,
                'travel_minutes' => round(($bestSchool->travel_seconds ?? 0) / 60, 1),
                'effective_minutes' => $bestEffectiveMinutes !== null ? round($bestEffectiveMinutes, 1) : null,
                'scoring_mode' => 'isochrone',
                'costing' => $costing,
                'schools_found' => count($grundskolor),
                'schools' => $schoolDetails,
            ],
        );
    }

    /**
     * @param  array<int, object>  $allPois
     * @param  string[]  $categories
     */
    private function scoreCategoryByTime(array $allPois, string $factorName, array $categories, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxMinutes = $this->getMaxMinutes($factorName, $urbanityTier);
        $sensitivity = match ($factorName) {
            'green_space' => (float) ($settings->get('park')?->safety_sensitivity ?? 1.00),
            'grocery' => (float) ($settings->get('grocery')?->safety_sensitivity ?? 0.30),
            default => 0.50,
        };
        $slug = "prox_{$factorName}";

        $filtered = array_values(array_filter($allPois, fn ($p) => in_array($p->category, $categories)));

        if (empty($filtered)) {
            return new ProximityFactor(
                slug: $slug,
                score: 0,
                details: ['message' => "No {$factorName} within isochrone", 'scoring_mode' => 'isochrone'],
            );
        }

        // Sort by travel time (nulls last)
        usort($filtered, fn ($a, $b) => ($a->travel_seconds ?? PHP_INT_MAX) <=> ($b->travel_seconds ?? PHP_INT_MAX));

        $nearest = $filtered[0];
        $decay = $this->decayWithSafetyTime($nearest->travel_seconds ?? null, $maxMinutes, $safetyScore, $sensitivity);

        $detailKeyMap = [
            'green_space' => 'nearest_park',
            'grocery' => 'nearest_store',
        ];
        $detailKey = $detailKeyMap[$factorName] ?? 'nearest';

        return new ProximityFactor(
            slug: $slug,
            score: (int) round($decay * 100),
            details: [
                $detailKey => $nearest->name,
                'distance_m' => (int) round($nearest->distance_m),
                'travel_seconds' => $nearest->travel_seconds,
                'travel_minutes' => ($nearest->travel_seconds ?? null) !== null ? round($nearest->travel_seconds / 60, 1) : null,
                'scoring_mode' => 'isochrone',
            ],
        );
    }

    /**
     * @param  array<int, object>  $stops
     */
    private function scoreTransitByTime(array $stops, string $urbanityTier, float $safetyScore, Collection $settings, string $costing): ProximityFactor
    {
        $maxMinutes = $this->getMaxMinutes('transit', $urbanityTier);
        $sensitivity = (float) ($settings->get('public_transport_stop')?->safety_sensitivity ?? 0.50);

        if (empty($stops)) {
            return new ProximityFactor(
                slug: 'prox_transit',
                score: 0,
                details: ['message' => 'No transit within isochrone', 'scoring_mode' => 'isochrone'],
            );
        }

        $bestScore = 0;
        $bestStop = $stops[0];

        foreach ($stops as $stop) {
            $decay = $this->decayWithSafetyTime($stop->travel_seconds ?? null, $maxMinutes, $safetyScore, $sensitivity);

            $modeWeight = match ($stop->stop_type ?? $stop->subcategory ?? '') {
                'rail', 'subway', 'station', 'train' => 1.5,
                'tram', 'tram_stop' => 1.2,
                default => 1.0,
            };

            $freqBonus = ($stop->weekly_departures ?? null)
                ? min(1.5, 0.5 + log10(max(1, $stop->weekly_departures / 7)) / 3)
                : 1.0;

            $stopScore = $decay * $modeWeight * $freqBonus;

            if ($stopScore > $bestScore) {
                $bestScore = $stopScore;
                $bestStop = $stop;
            }
        }

        $countBonus = min(0.2, count($stops) * 0.02);
        $finalScore = min(1.0, $bestScore + $countBonus);

        return new ProximityFactor(
            slug: 'prox_transit',
            score: (int) round(min(100, $finalScore * 100)),
            details: [
                'nearest_stop' => $stops[0]->name,
                'nearest_type' => $stops[0]->stop_type ?? $stops[0]->subcategory ?? 'bus',
                'nearest_distance_m' => (int) round($stops[0]->distance_m),
                'travel_seconds' => $stops[0]->travel_seconds ?? null,
                'travel_minutes' => ($stops[0]->travel_seconds ?? null) !== null ? round($stops[0]->travel_seconds / 60, 1) : null,
                'scoring_mode' => 'isochrone',
                'costing' => $costing,
                'stops_found' => count($stops),
                'weekly_departures' => $bestStop->weekly_departures ?? null,
            ],
        );
    }

    /**
     * @param  array<int, object>  $allPois
     */
    private function scoreNegativeByTime(array $allPois, string $urbanityTier): ProximityFactor
    {
        $maxMinutes = $this->getMaxMinutes('negative_poi', $urbanityTier);

        // Get negative POIs (need to know signal from category settings)
        $settings = $this->getCategorySettings();
        $negativeSlugs = $settings->filter(fn ($cat) => $cat->signal === 'negative')->keys()->all();

        $negPois = array_values(array_filter($allPois, fn ($p) => in_array($p->category, $negativeSlugs)));

        if (empty($negPois)) {
            return new ProximityFactor(
                slug: 'prox_negative_poi',
                score: 100,
                details: ['count' => 0, 'scoring_mode' => 'isochrone'],
            );
        }

        $penalty = 0;
        foreach ($negPois as $poi) {
            $travelMinutes = ($poi->travel_seconds ?? null) !== null ? $poi->travel_seconds / 60.0 : $maxMinutes;
            $decay = max(0, 1 - $travelMinutes / $maxMinutes);
            $penalty += $decay * 20;
        }

        $score = max(0, 100 - $penalty);

        return new ProximityFactor(
            slug: 'prox_negative_poi',
            score: (int) round($score),
            details: [
                'count' => count($negPois),
                'nearest' => $negPois[0]->name ?? $negPois[0]->category,
                'nearest_distance_m' => (int) round($negPois[0]->distance_m),
                'travel_seconds' => $negPois[0]->travel_seconds ?? null,
                'travel_minutes' => ($negPois[0]->travel_seconds ?? null) !== null ? round($negPois[0]->travel_seconds / 60, 1) : null,
                'scoring_mode' => 'isochrone',
            ],
        );
    }

    /**
     * @param  array<int, object>  $allPois
     */
    private function scorePositiveByTime(array $allPois, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxMinutes = $this->getMaxMinutes('positive_poi', $urbanityTier);

        $excludeCategories = ['grocery', 'public_transport_stop', 'park', 'nature_reserve'];
        $positiveSlugs = $settings->filter(fn ($cat) => $cat->signal === 'positive')->keys()->all();

        $posPois = array_values(array_filter($allPois, fn ($p) => in_array($p->category, $positiveSlugs) && ! in_array($p->category, $excludeCategories)));

        if (empty($posPois)) {
            return new ProximityFactor(
                slug: 'prox_positive_poi',
                score: 0,
                details: ['count' => 0, 'scoring_mode' => 'isochrone'],
            );
        }

        // Sort by travel time
        usort($posPois, fn ($a, $b) => ($a->travel_seconds ?? PHP_INT_MAX) <=> ($b->travel_seconds ?? PHP_INT_MAX));

        $bonus = 0;
        foreach ($posPois as $i => $poi) {
            $poiSensitivity = (float) ($settings->get($poi->category)?->safety_sensitivity ?? 1.00);
            $decay = $this->decayWithSafetyTime($poi->travel_seconds ?? null, $maxMinutes, $safetyScore, $poiSensitivity);
            $diminishing = 1 / ($i + 1);
            $bonus += $decay * 15 * $diminishing;
        }

        $score = min(100, $bonus);

        return new ProximityFactor(
            slug: 'prox_positive_poi',
            score: (int) round($score),
            details: [
                'count' => count($posPois),
                'types' => array_values(array_unique(array_column($posPois, 'category'))),
                'scoring_mode' => 'isochrone',
            ],
        );
    }

    // ─── Isochrone spatial queries ───────────────────────────────────────

    /**
     * @return array<int, object>
     */
    private function querySchoolsInPolygon(float $lng, float $lat, string $boundaryWkt): array
    {
        return DB::select('
            SELECT s.name, s.school_unit_code, s.type_of_schooling, s.operator_type,
                   s.lat, s.lng,
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
              AND s.type_of_schooling ILIKE \'%grundskola%\'
              AND s.geom IS NOT NULL
              AND ST_Contains(
                  ST_SetSRID(ST_GeomFromText(?), 4326),
                  s.geom
              )
            ORDER BY distance_m
            LIMIT 15
        ', [$lng, $lat, $boundaryWkt]);
    }

    /**
     * @return array<int, object>
     */
    private function queryPoisInPolygon(float $lng, float $lat, string $boundaryWkt): array
    {
        return DB::select('
            SELECT p.name, p.category, p.subcategory, p.lat, p.lng,
                   ST_Distance(
                       p.geom::geography,
                       ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                   ) as distance_m
            FROM pois p
            WHERE p.status = \'active\'
              AND p.geom IS NOT NULL
              AND ST_Contains(
                  ST_SetSRID(ST_GeomFromText(?), 4326),
                  p.geom
              )
            ORDER BY distance_m
            LIMIT 200
        ', [$lng, $lat, $boundaryWkt]);
    }

    /**
     * @return array<int, object>
     */
    private function queryTransitInPolygon(float $lng, float $lat, string $boundaryWkt): array
    {
        $stops = DB::select('
            SELECT name, stop_type, weekly_departures, lat, lng,
                   ST_Distance(
                       geom::geography,
                       ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                   ) as distance_m
            FROM transit_stops
            WHERE geom IS NOT NULL
              AND ST_Contains(
                  ST_SetSRID(ST_GeomFromText(?), 4326),
                  geom
              )
            ORDER BY distance_m
            LIMIT 20
        ', [$lng, $lat, $boundaryWkt]);

        // Fallback to pois table if transit_stops is empty
        if (empty($stops)) {
            $stops = DB::select("
                SELECT name, subcategory as stop_type, NULL as weekly_departures, lat, lng,
                       ST_Distance(
                           geom::geography,
                           ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                       ) as distance_m
                FROM pois
                WHERE category = 'public_transport_stop'
                  AND status = 'active'
                  AND geom IS NOT NULL
                  AND ST_Contains(
                      ST_SetSRID(ST_GeomFromText(?), 4326),
                      geom
                  )
                ORDER BY distance_m
                LIMIT 20
            ", [$lng, $lat, $boundaryWkt]);
        }

        return $stops;
    }

    // ─── Time-based decay ────────────────────────────────────────────────

    private function decayWithSafetyTime(
        ?int $travelSeconds,
        float $maxMinutes,
        float $safetyScore,
        float $safetySensitivity,
    ): float {
        if ($travelSeconds === null) {
            return 0.0;
        }

        $travelMinutes = $travelSeconds / 60.0;
        $riskPenalty = (1.0 - $safetyScore) * $safetySensitivity;
        $effectiveMinutes = $travelMinutes * (1.0 + $riskPenalty);

        return max(0.0, 1.0 - $effectiveMinutes / $maxMinutes);
    }

    private function getMaxMinutes(string $category, string $urbanityTier): float
    {
        $times = config("proximity.scoring_times.{$category}");
        if (is_array($times)) {
            return (float) ($times[$urbanityTier] ?? $times['semi_urban'] ?? 10);
        }

        return (float) $times;
    }

    // ─── Radius-based scoring (fallback) ─────────────────────────────────

    private function scoreWithRadius(float $lat, float $lng, string $urbanityTier, float $safetyScore, Collection $settings): ProximityResult
    {
        return new ProximityResult(
            school: $this->scoreSchoolByRadius($lat, $lng, $urbanityTier, $safetyScore, $settings),
            greenSpace: $this->scoreGreenSpaceByRadius($lat, $lng, $urbanityTier, $safetyScore, $settings),
            transit: $this->scoreTransitByRadius($lat, $lng, $urbanityTier, $safetyScore, $settings),
            grocery: $this->scoreGroceryByRadius($lat, $lng, $urbanityTier, $safetyScore, $settings),
            negativePoi: $this->scoreNegativePoisByRadius($lat, $lng, $urbanityTier),
            positivePoi: $this->scorePositivePoisByRadius($lat, $lng, $urbanityTier, $safetyScore, $settings),
            safetyScore: $safetyScore,
            urbanityTier: $urbanityTier,
        );
    }

    private function getRadius(string $category, string $urbanityTier): float
    {
        $radii = config("proximity.scoring_radii.{$category}");

        if (is_array($radii)) {
            return (float) ($radii[$urbanityTier] ?? $radii['semi_urban'] ?? 1000);
        }

        return (float) $radii;
    }

    private function decayWithSafety(
        float $physicalDistanceM,
        float $maxDistanceM,
        float $safetyScore,
        float $safetySensitivity,
    ): float {
        $riskPenalty = (1.0 - $safetyScore) * $safetySensitivity;
        $effectiveDistance = $physicalDistanceM * (1.0 + $riskPenalty);

        return max(0.0, 1.0 - $effectiveDistance / $maxDistanceM);
    }

    private function effectiveDistance(
        float $physicalDistanceM,
        float $safetyScore,
        float $safetySensitivity,
    ): float {
        $riskPenalty = (1.0 - $safetyScore) * $safetySensitivity;

        return $physicalDistanceM * (1.0 + $riskPenalty);
    }

    private function scoreSchoolByRadius(float $lat, float $lng, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = $this->getRadius('school', $urbanityTier);
        $sensitivity = (float) ($settings->get('school_grundskola')?->safety_sensitivity ?? 0.80);

        $schools = DB::select('
            SELECT s.name, s.school_unit_code, s.type_of_schooling, s.operator_type,
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
              AND s.type_of_schooling ILIKE \'%grundskola%\'
              AND s.geom IS NOT NULL
              AND ST_DWithin(
                  s.geom::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  ?
              )
            ORDER BY distance_m
            LIMIT 10
        ', [$lng, $lat, $lng, $lat, $maxDistance]);

        $schoolDetails = collect($schools)->map(fn ($s) => [
            'name' => $s->name,
            'type' => $s->type_of_schooling,
            'operator' => $s->operator_type,
            'distance_m' => (int) round($s->distance_m),
            'merit_value' => $s->merit_value_17 !== null ? (float) $s->merit_value_17 : null,
            'goal_achievement' => $s->goal_achievement_pct !== null ? (float) $s->goal_achievement_pct : null,
            'teacher_certification' => $s->teacher_certification_pct !== null ? (float) $s->teacher_certification_pct : null,
            'student_count' => $s->student_count,
        ])->values()->all();

        if (empty($schools)) {
            $nearest = DB::selectOne('
                SELECT s.name,
                       ST_Distance(s.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
                FROM schools s
                WHERE s.status = \'active\'
                  AND s.type_of_schooling ILIKE \'%grundskola%\'
                  AND s.geom IS NOT NULL
                ORDER BY s.geom::geography <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                LIMIT 1
            ', [$lng, $lat, $lng, $lat]);

            return new ProximityFactor(
                slug: 'prox_school',
                score: 0,
                details: [
                    'message' => "No grundskola within {$this->formatDistance($maxDistance)}",
                    'nearest_school' => $nearest?->name,
                    'nearest_distance_m' => $nearest ? (int) round($nearest->distance_m) : null,
                    'scoring_mode' => 'radius',
                    'schools' => [],
                ],
            );
        }

        $bestScore = 0;
        $bestSchool = null;
        $schoolsWithMerit = 0;

        foreach ($schools as $school) {
            if ($school->merit_value_17 === null) {
                continue;
            }

            $schoolsWithMerit++;

            $qualityNorm = min(1.0, max(0, ($school->merit_value_17 - 150) / 130));
            $decay = $this->decayWithSafety($school->distance_m, $maxDistance, $safetyScore, $sensitivity);
            $combined = $qualityNorm * $decay;

            if ($combined > $bestScore) {
                $bestScore = $combined;
                $bestSchool = $school;
            }
        }

        if ($schoolsWithMerit === 0 || $bestSchool === null) {
            $nearest = $schools[0];
            $decay = $this->decayWithSafety($nearest->distance_m, $maxDistance, $safetyScore, $sensitivity);

            return new ProximityFactor(
                slug: 'prox_school',
                score: (int) round($decay * 50),
                details: [
                    'nearest_school' => $nearest->name,
                    'nearest_distance_m' => (int) round($nearest->distance_m),
                    'effective_distance_m' => (int) round($this->effectiveDistance($nearest->distance_m, $safetyScore, $sensitivity)),
                    'scoring_mode' => 'radius',
                    'schools_found' => count($schools),
                    'merit_data' => $schoolsWithMerit > 0,
                    'schools' => $schoolDetails,
                ],
            );
        }

        return new ProximityFactor(
            slug: 'prox_school',
            score: (int) round($bestScore * 100),
            details: [
                'nearest_school' => $bestSchool->name,
                'nearest_merit' => (float) $bestSchool->merit_value_17,
                'nearest_distance_m' => (int) round($bestSchool->distance_m),
                'effective_distance_m' => (int) round($this->effectiveDistance($bestSchool->distance_m, $safetyScore, $sensitivity)),
                'scoring_mode' => 'radius',
                'schools_found' => count($schools),
                'schools' => $schoolDetails,
            ],
        );
    }

    private function scoreGreenSpaceByRadius(float $lat, float $lng, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = $this->getRadius('green_space', $urbanityTier);
        $sensitivity = (float) ($settings->get('park')?->safety_sensitivity ?? 1.00);

        $nearest = DB::selectOne("
            SELECT name,
                   ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM pois
            WHERE category IN ('park', 'nature_reserve')
              AND status = 'active'
              AND geom IS NOT NULL
              AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
            LIMIT 1
        ", [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);

        if (! $nearest) {
            return new ProximityFactor(
                slug: 'prox_green_space',
                score: 0,
                details: ['message' => "No park within {$this->formatDistance($maxDistance)}", 'scoring_mode' => 'radius'],
            );
        }

        $decay = $this->decayWithSafety($nearest->distance_m, $maxDistance, $safetyScore, $sensitivity);

        return new ProximityFactor(
            slug: 'prox_green_space',
            score: (int) round($decay * 100),
            details: [
                'nearest_park' => $nearest->name,
                'distance_m' => (int) round($nearest->distance_m),
                'effective_distance_m' => (int) round($this->effectiveDistance($nearest->distance_m, $safetyScore, $sensitivity)),
                'scoring_mode' => 'radius',
            ],
        );
    }

    private function scoreTransitByRadius(float $lat, float $lng, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = $this->getRadius('transit', $urbanityTier);
        $sensitivity = (float) ($settings->get('public_transport_stop')?->safety_sensitivity ?? 0.50);

        $stops = DB::select('
            SELECT name, stop_type, weekly_departures,
                   ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM transit_stops
            WHERE geom IS NOT NULL
              AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
            LIMIT 10
        ', [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);

        if (empty($stops)) {
            $stops = DB::select("
                SELECT name, subcategory as stop_type, NULL as weekly_departures,
                       ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
                FROM pois
                WHERE category = 'public_transport_stop'
                  AND status = 'active'
                  AND geom IS NOT NULL
                  AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
                ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
                LIMIT 10
            ", [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);
        }

        if (empty($stops)) {
            return new ProximityFactor(
                slug: 'prox_transit',
                score: 0,
                details: ['message' => "No transit within {$this->formatDistance($maxDistance)}", 'scoring_mode' => 'radius'],
            );
        }

        $bestScore = 0;
        $bestStop = $stops[0];

        foreach ($stops as $stop) {
            $decay = $this->decayWithSafety($stop->distance_m, $maxDistance, $safetyScore, $sensitivity);

            $modeWeight = match ($stop->stop_type) {
                'rail', 'subway', 'station', 'train' => 1.5,
                'tram', 'tram_stop' => 1.2,
                default => 1.0,
            };

            $freqBonus = $stop->weekly_departures
                ? min(1.5, 0.5 + log10(max(1, $stop->weekly_departures / 7)) / 3)
                : 1.0;

            $stopScore = $decay * $modeWeight * $freqBonus;

            if ($stopScore > $bestScore) {
                $bestScore = $stopScore;
                $bestStop = $stop;
            }
        }

        $countBonus = min(0.2, count($stops) * 0.02);
        $finalScore = min(1.0, $bestScore + $countBonus);

        return new ProximityFactor(
            slug: 'prox_transit',
            score: (int) round(min(100, $finalScore * 100)),
            details: [
                'nearest_stop' => $stops[0]->name,
                'nearest_type' => $stops[0]->stop_type ?? 'bus',
                'nearest_distance_m' => (int) round($stops[0]->distance_m),
                'effective_distance_m' => (int) round($this->effectiveDistance($stops[0]->distance_m, $safetyScore, $sensitivity)),
                'scoring_mode' => 'radius',
                'stops_found' => count($stops),
                'weekly_departures' => $bestStop->weekly_departures,
            ],
        );
    }

    private function scoreGroceryByRadius(float $lat, float $lng, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = $this->getRadius('grocery', $urbanityTier);
        $sensitivity = (float) ($settings->get('grocery')?->safety_sensitivity ?? 0.30);

        $nearest = DB::selectOne("
            SELECT name,
                   ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM pois
            WHERE category = 'grocery'
              AND status = 'active'
              AND geom IS NOT NULL
              AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
            LIMIT 1
        ", [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);

        if (! $nearest) {
            return new ProximityFactor(
                slug: 'prox_grocery',
                score: 0,
                details: ['message' => "No grocery within {$this->formatDistance($maxDistance)}", 'scoring_mode' => 'radius'],
            );
        }

        $decay = $this->decayWithSafety($nearest->distance_m, $maxDistance, $safetyScore, $sensitivity);

        return new ProximityFactor(
            slug: 'prox_grocery',
            score: (int) round($decay * 100),
            details: [
                'nearest_store' => $nearest->name,
                'distance_m' => (int) round($nearest->distance_m),
                'effective_distance_m' => (int) round($this->effectiveDistance($nearest->distance_m, $safetyScore, $sensitivity)),
                'scoring_mode' => 'radius',
            ],
        );
    }

    private function scoreNegativePoisByRadius(float $lat, float $lng, string $urbanityTier): ProximityFactor
    {
        $maxDistance = $this->getRadius('negative_poi', $urbanityTier);

        $pois = DB::select('
            SELECT p.name, p.category, p.subcategory,
                   ST_Distance(p.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM pois p
            JOIN poi_categories pc ON pc.slug = p.category
            WHERE pc.signal = \'negative\'
              AND p.status = \'active\'
              AND p.geom IS NOT NULL
              AND ST_DWithin(p.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY distance_m
        ', [$lng, $lat, $lng, $lat, $maxDistance]);

        if (empty($pois)) {
            return new ProximityFactor(
                slug: 'prox_negative_poi',
                score: 100,
                details: ['count' => 0, 'scoring_mode' => 'radius'],
            );
        }

        $penalty = 0;
        foreach ($pois as $poi) {
            $decay = max(0, 1 - $poi->distance_m / $maxDistance);
            $penalty += $decay * 20;
        }

        $score = max(0, 100 - $penalty);

        return new ProximityFactor(
            slug: 'prox_negative_poi',
            score: (int) round($score),
            details: [
                'count' => count($pois),
                'nearest' => $pois[0]->name ?? $pois[0]->category,
                'nearest_distance_m' => (int) round($pois[0]->distance_m),
                'scoring_mode' => 'radius',
            ],
        );
    }

    private function scorePositivePoisByRadius(float $lat, float $lng, string $urbanityTier, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = $this->getRadius('positive_poi', $urbanityTier);

        $excludeCategories = "'grocery', 'public_transport_stop', 'park', 'nature_reserve'";

        $pois = DB::select("
            SELECT p.name, p.category, p.subcategory,
                   ST_Distance(p.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM pois p
            JOIN poi_categories pc ON pc.slug = p.category
            WHERE pc.signal = 'positive'
              AND p.category NOT IN ({$excludeCategories})
              AND p.status = 'active'
              AND p.geom IS NOT NULL
              AND ST_DWithin(p.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY distance_m
            LIMIT 20
        ", [$lng, $lat, $lng, $lat, $maxDistance]);

        if (empty($pois)) {
            return new ProximityFactor(
                slug: 'prox_positive_poi',
                score: 0,
                details: ['count' => 0, 'scoring_mode' => 'radius'],
            );
        }

        $bonus = 0;
        foreach ($pois as $i => $poi) {
            $poiSensitivity = (float) ($settings->get($poi->category)?->safety_sensitivity ?? 1.00);
            $decay = $this->decayWithSafety($poi->distance_m, $maxDistance, $safetyScore, $poiSensitivity);
            $diminishing = 1 / ($i + 1);
            $bonus += $decay * 15 * $diminishing;
        }

        $score = min(100, $bonus);

        return new ProximityFactor(
            slug: 'prox_positive_poi',
            score: (int) round($score),
            details: [
                'count' => count($pois),
                'types' => array_values(array_unique(array_column($pois, 'category'))),
                'scoring_mode' => 'radius',
            ],
        );
    }

    private function formatDistance(float $meters): string
    {
        return $meters >= 1000
            ? round($meters / 1000, 1).'km'
            : (int) $meters.'m';
    }

    /**
     * @return Collection<string, PoiCategory>
     */
    private function getCategorySettings(): Collection
    {
        return Cache::remember('poi_category_settings', 3600, function () {
            return PoiCategory::all()->keyBy('slug');
        });
    }
}
