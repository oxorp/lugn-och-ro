<?php

namespace App\Services;

use App\DataTransferObjects\ProximityFactor;
use App\DataTransferObjects\ProximityResult;
use App\Models\PoiCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProximityScoreService
{
    public function __construct(
        private SafetyScoreService $safety,
    ) {}

    /**
     * Compute proximity scores for a specific coordinate.
     * Returns a 0-100 composite score and breakdown of each proximity factor.
     *
     * This runs on every pin drop — must be fast (<200ms).
     */
    public function score(float $lat, float $lng): ProximityResult
    {
        // Resolve DeSO for the pin to get safety context
        $deso = DB::selectOne('
            SELECT deso_code FROM deso_areas
            WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ', [$lng, $lat]);

        $safetyScore = $deso
            ? $this->safety->forDeso($deso->deso_code, now()->year - 1)
            : 0.5;

        $settings = $this->getCategorySettings();

        return new ProximityResult(
            school: $this->scoreSchool($lat, $lng, $safetyScore, $settings),
            greenSpace: $this->scoreGreenSpace($lat, $lng, $safetyScore, $settings),
            transit: $this->scoreTransit($lat, $lng, $safetyScore, $settings),
            grocery: $this->scoreGrocery($lat, $lng, $safetyScore, $settings),
            negativePoi: $this->scoreNegativePois($lat, $lng),
            positivePoi: $this->scorePositivePois($lat, $lng, $safetyScore, $settings),
            safetyScore: $safetyScore,
        );
    }

    /**
     * Apply safety-modulated distance decay.
     *
     * In safe areas (safety ~1.0), effective_distance ≈ physical_distance.
     * In unsafe areas (safety ~0.15), effective_distance can be 2-3x physical_distance
     * for high-sensitivity categories like entertainment.
     */
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

    /**
     * Compute effective distance for a physical distance given safety context.
     */
    private function effectiveDistance(
        float $physicalDistanceM,
        float $safetyScore,
        float $safetySensitivity,
    ): float {
        $riskPenalty = (1.0 - $safetyScore) * $safetySensitivity;

        return $physicalDistanceM * (1.0 + $riskPenalty);
    }

    private function scoreSchool(float $lat, float $lng, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = (int) config('proximity.scoring_radii.school', 2000);
        $sensitivity = (float) ($settings->get('school_grundskola')?->safety_sensitivity ?? 0.80);

        $schools = DB::select("
            SELECT s.name, s.school_unit_code,
                   ss.merit_value_17,
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
            WHERE s.status = 'active'
              AND s.type_of_schooling ILIKE '%grundskola%'
              AND s.geom IS NOT NULL
              AND ST_DWithin(
                  s.geom::geography,
                  ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                  ?
              )
            ORDER BY distance_m
            LIMIT 5
        ", [$lng, $lat, $lng, $lat, $maxDistance]);

        if (empty($schools)) {
            return new ProximityFactor(
                slug: 'prox_school',
                score: 0,
                details: ['message' => 'No grundskola within 2km'],
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

        // No merit data, or safety modulation pushed all effective distances beyond max
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
                    'schools_within_2km' => count($schools),
                    'merit_data' => $schoolsWithMerit > 0,
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
                'schools_within_2km' => count($schools),
            ],
        );
    }

    private function scoreGreenSpace(float $lat, float $lng, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = (int) config('proximity.scoring_radii.green_space', 1500);
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
                details: ['message' => 'No park within 1.5km'],
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
            ],
        );
    }

    private function scoreTransit(float $lat, float $lng, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = (int) config('proximity.scoring_radii.transit', 1000);
        $sensitivity = (float) ($settings->get('public_transport_stop')?->safety_sensitivity ?? 0.50);

        $stops = DB::select("
            SELECT name, subcategory,
                   ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM pois
            WHERE category = 'public_transport_stop'
              AND status = 'active'
              AND geom IS NOT NULL
              AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
            LIMIT 10
        ", [$lng, $lat, $lng, $lat, $maxDistance, $lng, $lat]);

        if (empty($stops)) {
            return new ProximityFactor(
                slug: 'prox_transit',
                score: 0,
                details: ['message' => 'No transit within 1km'],
            );
        }

        $score = 0;
        foreach ($stops as $stop) {
            $decay = $this->decayWithSafety($stop->distance_m, $maxDistance, $safetyScore, $sensitivity);

            $modeWeight = match ($stop->subcategory) {
                'station', 'train' => 1.5,
                'tram_stop' => 1.2,
                default => 1.0,
            };

            $stopScore = $decay * $modeWeight;
            $score = max($score, $stopScore);
        }

        $countBonus = min(0.2, count($stops) * 0.02);
        $score = min(1.0, $score + $countBonus);

        return new ProximityFactor(
            slug: 'prox_transit',
            score: (int) round(min(100, $score * 100)),
            details: [
                'nearest_stop' => $stops[0]->name,
                'nearest_type' => $stops[0]->subcategory,
                'nearest_distance_m' => (int) round($stops[0]->distance_m),
                'effective_distance_m' => (int) round($this->effectiveDistance($stops[0]->distance_m, $safetyScore, $sensitivity)),
                'stops_within_1km' => count($stops),
            ],
        );
    }

    private function scoreGrocery(float $lat, float $lng, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = (int) config('proximity.scoring_radii.grocery', 1000);
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
                details: ['message' => 'No grocery within 1km'],
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
            ],
        );
    }

    private function scoreNegativePois(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = (int) config('proximity.scoring_radii.negative_poi', 500);

        $pois = DB::select("
            SELECT p.name, p.category, p.subcategory,
                   ST_Distance(p.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as distance_m
            FROM pois p
            JOIN poi_categories pc ON pc.slug = p.category
            WHERE pc.signal = 'negative'
              AND p.status = 'active'
              AND p.geom IS NOT NULL
              AND ST_DWithin(p.geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY distance_m
        ", [$lng, $lat, $lng, $lat, $maxDistance]);

        if (empty($pois)) {
            return new ProximityFactor(
                slug: 'prox_negative_poi',
                score: 100,
                details: ['count' => 0],
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
            ],
        );
    }

    private function scorePositivePois(float $lat, float $lng, float $safetyScore, Collection $settings): ProximityFactor
    {
        $maxDistance = (int) config('proximity.scoring_radii.positive_poi', 1000);

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
                details: ['count' => 0],
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
            ],
        );
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
