<?php

namespace App\Services;

use App\DataTransferObjects\ProximityFactor;
use App\DataTransferObjects\ProximityResult;
use Illuminate\Support\Facades\DB;

class ProximityScoreService
{
    /**
     * Compute proximity scores for a specific coordinate.
     * Returns a 0-100 composite score and breakdown of each proximity factor.
     *
     * This runs on every pin drop — must be fast (<200ms).
     */
    public function score(float $lat, float $lng): ProximityResult
    {
        return new ProximityResult(
            school: $this->scoreSchool($lat, $lng),
            greenSpace: $this->scoreGreenSpace($lat, $lng),
            transit: $this->scoreTransit($lat, $lng),
            grocery: $this->scoreGrocery($lat, $lng),
            negativePoi: $this->scoreNegativePois($lat, $lng),
            positivePoi: $this->scorePositivePois($lat, $lng),
        );
    }

    private function scoreSchool(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = 2000; // 2km

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
            WHERE s.status = 'AKTIV'
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

        // Score = best school's quality x distance decay
        $bestScore = 0;
        $bestSchool = null;
        $schoolsWithMerit = 0;

        foreach ($schools as $school) {
            if ($school->merit_value_17 === null) {
                continue;
            }

            $schoolsWithMerit++;

            // Normalize merit value: 150=0, 280=1 (roughly)
            $qualityNorm = min(1.0, max(0, ($school->merit_value_17 - 150) / 130));

            // Linear distance decay
            $decay = max(0, 1 - $school->distance_m / $maxDistance);

            $combined = $qualityNorm * $decay;

            if ($combined > $bestScore) {
                $bestScore = $combined;
                $bestSchool = $school;
            }
        }

        // If schools exist but none have merit data, give partial credit for proximity
        if ($schoolsWithMerit === 0) {
            $nearest = $schools[0];
            $decay = max(0, 1 - $nearest->distance_m / $maxDistance);

            return new ProximityFactor(
                slug: 'prox_school',
                score: (int) round($decay * 50), // Half credit without quality data
                details: [
                    'nearest_school' => $nearest->name,
                    'nearest_distance_m' => (int) round($nearest->distance_m),
                    'schools_within_2km' => count($schools),
                    'merit_data' => false,
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
                'schools_within_2km' => count($schools),
            ],
        );
    }

    private function scoreGreenSpace(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = 1000; // 1km

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
                details: ['message' => 'No park within 1km'],
            );
        }

        $decay = max(0, 1 - $nearest->distance_m / $maxDistance);

        return new ProximityFactor(
            slug: 'prox_green_space',
            score: (int) round($decay * 100),
            details: [
                'nearest_park' => $nearest->name,
                'distance_m' => (int) round($nearest->distance_m),
            ],
        );
    }

    private function scoreTransit(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = 1000; // 1km

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

        // Score based on closest stop distance + mode bonus
        $score = 0;
        foreach ($stops as $stop) {
            $decay = max(0, 1 - $stop->distance_m / $maxDistance);

            // Mode weight: rail > tram > bus
            $modeWeight = match ($stop->subcategory) {
                'station', 'train' => 1.5,
                'tram_stop' => 1.2,
                default => 1.0,
            };

            $stopScore = $decay * $modeWeight;
            $score = max($score, $stopScore);
        }

        // Bonus for multiple stops nearby (max 20% bonus)
        $countBonus = min(0.2, count($stops) * 0.02);
        $score = min(1.0, $score + $countBonus);

        return new ProximityFactor(
            slug: 'prox_transit',
            score: (int) round(min(100, $score * 100)),
            details: [
                'nearest_stop' => $stops[0]->name,
                'nearest_type' => $stops[0]->subcategory,
                'nearest_distance_m' => (int) round($stops[0]->distance_m),
                'stops_within_1km' => count($stops),
            ],
        );
    }

    private function scoreGrocery(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = 1000; // 1km

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

        $decay = max(0, 1 - $nearest->distance_m / $maxDistance);

        return new ProximityFactor(
            slug: 'prox_grocery',
            score: (int) round($decay * 100),
            details: [
                'nearest_store' => $nearest->name,
                'distance_m' => (int) round($nearest->distance_m),
            ],
        );
    }

    private function scoreNegativePois(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = 500; // 500m — negative POIs only matter if very close

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
            // No negative POIs nearby = full score (good)
            return new ProximityFactor(
                slug: 'prox_negative_poi',
                score: 100,
                details: ['count' => 0],
            );
        }

        // Each nearby negative POI reduces the score, distance-weighted
        $penalty = 0;
        foreach ($pois as $poi) {
            $decay = max(0, 1 - $poi->distance_m / $maxDistance);
            $penalty += $decay * 20; // Each close negative POI costs up to 20 points
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

    private function scorePositivePois(float $lat, float $lng): ProximityFactor
    {
        $maxDistance = 1000; // 1km

        // Positive POIs excluding categories already scored separately
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

        // Each nearby positive POI adds to the score with diminishing returns
        $bonus = 0;
        foreach ($pois as $i => $poi) {
            $decay = max(0, 1 - $poi->distance_m / $maxDistance);
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
}
