<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IsochroneService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('proximity.isochrone.valhalla_url', 'http://valhalla:8002');
    }

    /**
     * Generate isochrone polygons for a coordinate.
     *
     * Returns GeoJSON FeatureCollection with one polygon per contour interval.
     * Each feature has a `contour` property (minutes) and `area_km2`.
     *
     * Results are cached by ~100m grid cell (same as ProximityScoreService).
     *
     * @param  int[]  $contours  Minutes for each ring, e.g. [5, 10, 15]
     * @return array{type: string, features: array}|null GeoJSON FeatureCollection or null on failure
     */
    public function generate(
        float $lat,
        float $lng,
        string $costing = 'pedestrian',
        array $contours = [5, 10, 15],
    ): ?array {
        $gridLat = round($lat, 3);
        $gridLng = round($lng, 3);
        $contourKey = implode('-', $contours);
        $cacheKey = "isochrone:{$costing}:{$contourKey}:{$gridLat},{$gridLng}";

        return Cache::remember($cacheKey, 3600, function () use ($lat, $lng, $costing, $contours) {
            return $this->fetchFromValhalla($lat, $lng, $costing, $contours);
        });
    }

    /**
     * Get the outermost isochrone polygon as a WKT string for PostGIS queries.
     * This is the "reachable area" used to filter POIs.
     */
    public function outermostPolygonWkt(
        float $lat,
        float $lng,
        string $costing = 'pedestrian',
        int $maxMinutes = 15,
    ): ?string {
        $geojson = $this->generate($lat, $lng, $costing, [$maxMinutes]);

        if (! $geojson || empty($geojson['features'])) {
            return null;
        }

        // Valhalla returns polygons ordered outermost first
        $outermost = $geojson['features'][0];

        return $this->geojsonPolygonToWkt($outermost['geometry']);
    }

    /**
     * Get walking/driving times from origin to multiple targets.
     *
     * @param  array<array{lat: float, lng: float}>  $targets
     * @return array<int|null> Travel time in seconds per target, null if unreachable
     */
    public function travelTimes(
        float $lat,
        float $lng,
        array $targets,
        string $costing = 'pedestrian',
    ): array {
        if (empty($targets)) {
            return [];
        }

        // Valhalla matrix has a limit of ~50 targets per call
        $allTimes = [];
        foreach (array_chunk($targets, 50) as $chunk) {
            $body = [
                'sources' => [['lat' => $lat, 'lon' => $lng]],
                'targets' => array_map(fn ($t) => ['lat' => $t['lat'], 'lon' => $t['lng']], $chunk),
                'costing' => $costing,
            ];

            try {
                $response = Http::timeout(5)
                    ->post("{$this->baseUrl}/sources_to_targets", $body);

                if (! $response->successful()) {
                    $allTimes = array_merge($allTimes, array_fill(0, count($chunk), null));

                    continue;
                }

                $data = $response->json();
                $row = $data['sources_to_targets'][0] ?? [];

                foreach ($row as $entry) {
                    $allTimes[] = ($entry['time'] ?? null) !== null
                        ? (int) $entry['time']
                        : null;
                }
            } catch (\Throwable $e) {
                Log::warning('Valhalla matrix error', ['message' => $e->getMessage()]);
                $allTimes = array_merge($allTimes, array_fill(0, count($chunk), null));
            }
        }

        return $allTimes;
    }

    /**
     * @return array{type: string, features: array}|null
     */
    private function fetchFromValhalla(float $lat, float $lng, string $costing, array $contours): ?array
    {
        $body = [
            'locations' => [['lat' => $lat, 'lon' => $lng]],
            'costing' => $costing,
            'contours' => array_map(fn ($min) => ['time' => $min], $contours),
            'polygons' => true,
            'generalize' => 50,
        ];

        try {
            $response = Http::timeout(5)
                ->post("{$this->baseUrl}/isochrone", $body);

            if (! $response->successful()) {
                Log::warning('Valhalla isochrone failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return null;
            }

            $geojson = $response->json();

            // Add area_km2 to each feature for display
            if (! empty($geojson['features'])) {
                foreach ($geojson['features'] as &$feature) {
                    $feature['properties']['area_km2'] = $this->estimateAreaKm2($feature['geometry']);
                }
            }

            return $geojson;
        } catch (\Throwable $e) {
            Log::error('Valhalla isochrone error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            return null;
        }
    }

    /**
     * Convert GeoJSON Polygon to WKT for PostGIS ST_Contains queries.
     */
    private function geojsonPolygonToWkt(array $geometry): string
    {
        $type = $geometry['type'];
        $coords = $geometry['coordinates'];

        if ($type === 'Polygon') {
            $rings = [];
            foreach ($coords as $ring) {
                $points = array_map(fn ($p) => "{$p[0]} {$p[1]}", $ring);
                $rings[] = '('.implode(', ', $points).')';
            }

            return 'POLYGON('.implode(', ', $rings).')';
        }

        // MultiPolygon — take the largest
        if ($type === 'MultiPolygon') {
            $ring = $coords[0][0];
            $points = array_map(fn ($p) => "{$p[0]} {$p[1]}", $ring);

            return 'POLYGON(('.implode(', ', $points).'))';
        }

        return '';
    }

    /**
     * Generate multiple reachability rings based on urbanity tier and user preferences.
     *
     * Ring configuration:
     * - All locations: Ring 1 = 5 min walking
     * - Urban/Semi-urban: Ring 2 = user's walking distance preference
     * - Rural + car: Ring 2 = user preference walk, Ring 3 = 10 min driving
     * - Rural + no car: Ring 2 = half preference walk, Ring 3 = full preference walk
     *
     * @param  string  $urbanityTier  'urban', 'semi_urban', or 'rural'
     * @param  int  $walkingDistanceMinutes  User's preferred walking distance (10, 15, 20, or 30)
     * @param  bool|null  $hasCar  Whether user has a car (only relevant for rural)
     * @return array{rings: array, geojson: array{type: string, features: array}}|null
     */
    public function generateMultipleRings(
        float $lat,
        float $lng,
        string $urbanityTier = 'urban',
        int $walkingDistanceMinutes = 15,
        ?bool $hasCar = null,
    ): ?array {
        $ringConfig = config('questionnaire.ring_config');
        $ringRules = config('questionnaire.ring_rules');

        // Build ring definitions based on urbanity and car ownership
        $ringDefinitions = $this->buildRingDefinitions(
            $urbanityTier,
            $walkingDistanceMinutes,
            $hasCar,
            $ringConfig,
            $ringRules
        );

        // Group rings by costing mode to minimize Valhalla calls
        $pedestrianContours = [];
        $autoContours = [];

        foreach ($ringDefinitions as $ringDef) {
            if ($ringDef['mode'] === 'pedestrian') {
                $pedestrianContours[$ringDef['minutes']] = $ringDef;
            } else {
                $autoContours[$ringDef['minutes']] = $ringDef;
            }
        }

        $allFeatures = [];

        // Generate pedestrian isochrones
        if (! empty($pedestrianContours)) {
            $contourMinutes = array_keys($pedestrianContours);
            sort($contourMinutes);
            $geojson = $this->generate($lat, $lng, 'pedestrian', $contourMinutes);

            if ($geojson && ! empty($geojson['features'])) {
                foreach ($geojson['features'] as $feature) {
                    $contour = $feature['properties']['contour'] ?? null;
                    if ($contour !== null && isset($pedestrianContours[$contour])) {
                        $ringDef = $pedestrianContours[$contour];
                        $feature['properties']['ring'] = $ringDef['ring'];
                        $feature['properties']['mode'] = 'pedestrian';
                        $feature['properties']['label'] = $ringDef['label'];
                        $feature['properties']['color'] = $ringDef['color'];
                        $allFeatures[] = $feature;
                    }
                }
            }
        }

        // Generate auto isochrones if needed (rural with car)
        if (! empty($autoContours)) {
            $contourMinutes = array_keys($autoContours);
            sort($contourMinutes);
            $geojson = $this->generate($lat, $lng, 'auto', $contourMinutes);

            if ($geojson && ! empty($geojson['features'])) {
                foreach ($geojson['features'] as $feature) {
                    $contour = $feature['properties']['contour'] ?? null;
                    if ($contour !== null && isset($autoContours[$contour])) {
                        $ringDef = $autoContours[$contour];
                        $feature['properties']['ring'] = $ringDef['ring'];
                        $feature['properties']['mode'] = 'auto';
                        $feature['properties']['label'] = $ringDef['label'];
                        $feature['properties']['color'] = $ringDef['color'];
                        $allFeatures[] = $feature;
                    }
                }
            }
        }

        if (empty($allFeatures)) {
            return null;
        }

        // Sort features by ring number (outermost first for proper layering)
        usort($allFeatures, fn ($a, $b) => ($b['properties']['ring'] ?? 0) <=> ($a['properties']['ring'] ?? 0));

        return [
            'rings' => array_values($ringDefinitions),
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => $allFeatures,
            ],
        ];
    }

    /**
     * Build ring definitions based on urbanity tier and user preferences.
     *
     * @return array<array{ring: int, minutes: int, mode: string, label: string, color: string}>
     */
    private function buildRingDefinitions(
        string $urbanityTier,
        int $walkingDistanceMinutes,
        ?bool $hasCar,
        array $ringConfig,
        array $ringRules,
    ): array {
        $rings = [];

        // Ring 1 is always 5 min walking
        $rings[] = [
            'ring' => 1,
            'minutes' => $ringConfig['ring_1']['minutes'],
            'mode' => $ringConfig['ring_1']['mode'],
            'label' => $ringConfig['ring_1']['label_sv'],
            'color' => $ringConfig['ring_1']['color'],
        ];

        // Get rules for this urbanity tier
        $rules = $ringRules[$urbanityTier] ?? $ringRules['urban'];

        // For rural, we need to check car ownership
        if ($urbanityTier === 'rural') {
            $rules = $hasCar
                ? ($ringRules['rural']['with_car'] ?? $rules)
                : ($ringRules['rural']['without_car'] ?? $rules);
        }

        // Build Ring 2
        if (isset($rules['ring_2'])) {
            $ring2Minutes = $this->resolveMinutes(
                $rules['ring_2'],
                $walkingDistanceMinutes
            );
            $ring2Mode = $rules['ring_2']['mode'] ?? 'pedestrian';
            $ring2Label = $this->formatRingLabel($ring2Minutes, $ring2Mode, $ringConfig);

            $rings[] = [
                'ring' => 2,
                'minutes' => $ring2Minutes,
                'mode' => $ring2Mode,
                'label' => $ring2Label,
                'color' => $ringConfig['ring_2_defaults']['color'],
            ];
        }

        // Build Ring 3 (only for rural)
        if (isset($rules['ring_3'])) {
            $ring3Minutes = $this->resolveMinutes(
                $rules['ring_3'],
                $walkingDistanceMinutes
            );
            $ring3Mode = $rules['ring_3']['mode'] ?? 'pedestrian';
            $ring3Label = $this->formatRingLabel($ring3Minutes, $ring3Mode, $ringConfig);

            $rings[] = [
                'ring' => 3,
                'minutes' => $ring3Minutes,
                'mode' => $ring3Mode,
                'label' => $ring3Label,
                'color' => $ringConfig['ring_3_defaults']['color'],
            ];
        }

        return $rings;
    }

    /**
     * Resolve the minutes for a ring based on the rule configuration.
     */
    private function resolveMinutes(array $ruleConfig, int $userPreferenceMinutes): int
    {
        // Fixed minutes
        if (isset($ruleConfig['minutes'])) {
            return $ruleConfig['minutes'];
        }

        // User preference (full or half)
        $source = $ruleConfig['source'] ?? 'user_preference';

        if ($source === 'user_preference_half') {
            return max(5, (int) floor($userPreferenceMinutes / 2));
        }

        return $userPreferenceMinutes;
    }

    /**
     * Format the Swedish label for a ring.
     */
    private function formatRingLabel(int $minutes, string $mode, array $ringConfig): string
    {
        $templateKey = $mode === 'auto' ? 'ring_3_defaults' : 'ring_2_defaults';
        $template = $ringConfig[$templateKey]['label_template_sv'] ?? 'Nåbart inom {minutes} min';

        return str_replace('{minutes}', (string) $minutes, $template);
    }

    /**
     * Rough area estimate from polygon coordinates (Shoelace formula on lat/lng).
     */
    private function estimateAreaKm2(array $geometry): float
    {
        $coords = $geometry['coordinates'][0] ?? [];
        if (count($coords) < 3) {
            return 0;
        }

        $area = 0;
        $n = count($coords);
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $area += $coords[$i][0] * $coords[$j][1];
            $area -= $coords[$j][0] * $coords[$i][1];
        }
        $area = abs($area) / 2;

        // Convert degree² to km² at Swedish latitudes (~59°N)
        // 1° lng ≈ 56 km, 1° lat ≈ 111 km at 59°N
        return round($area * 56 * 111, 2);
    }
}
