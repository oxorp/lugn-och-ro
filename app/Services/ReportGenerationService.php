<?php

namespace App\Services;

use App\Models\CompositeScore;
use App\Models\DesoArea;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\Report;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportGenerationService
{
    private const MODEL_VERSION = 'v1.0';

    /** Amenity density indicators excluded from area indicators (handled by proximity) */
    private const AMENITY_DENSITY_SLUGS = [
        'grocery_density', 'transit_stop_density', 'healthcare_density',
        'restaurant_density', 'fitness_density', 'gambling_density',
        'pawn_shop_density', 'fast_food_density',
    ];

    public function __construct(
        private VerdictService $verdictService,
        private ProximityScoreService $proximityService,
        private IsochroneService $isochroneService,
        private PersonalizedScoringService $personalizedScoringService,
    ) {}

    /**
     * Generate full report snapshot for a report record.
     */
    public function generate(Report $report): Report
    {
        $deso = DesoArea::where('deso_code', $report->deso_code)->first();

        if (! $deso) {
            return $report;
        }

        // 1. Active indicators & their historical values
        $activeIndicators = Indicator::where('is_active', true)->get()->keyBy('id');

        $allValues = IndicatorValue::where('deso_code', $deso->deso_code)
            ->whereIn('indicator_id', $activeIndicators->keys())
            ->whereNotNull('raw_value')
            ->orderBy('year')
            ->get()
            ->groupBy('indicator_id');

        // Build indicator arrays with full history
        $indicators = [];
        foreach ($allValues as $indicatorId => $history) {
            $indicator = $activeIndicators->get($indicatorId);
            if (! $indicator || in_array($indicator->slug, self::AMENITY_DENSITY_SLUGS, true)) {
                continue;
            }

            $current = $history->last();
            $indicators[] = $this->buildIndicatorSnapshot($indicator, $history, $current);
        }

        // 2. Composite score & history
        $latestScore = CompositeScore::where('deso_code', $deso->deso_code)
            ->orderByDesc('year')
            ->first();

        $scoreHistory = CompositeScore::where('deso_code', $deso->deso_code)
            ->orderBy('year')
            ->get(['year', 'score'])
            ->map(fn ($s) => ['year' => (int) $s->year, 'score' => round((float) $s->score, 1)])
            ->values()
            ->toArray();

        // 3. DeSO metadata
        $desoMeta = [
            'deso_code' => $deso->deso_code,
            'deso_name' => $deso->deso_name,
            'kommun_name' => $deso->kommun_name,
            'lan_name' => $deso->lan_name,
            'area_km2' => (float) $deso->area_km2,
            'population' => $deso->population ? (int) $deso->population : null,
            'urbanity_tier' => $deso->urbanity_tier,
        ];

        // 4. Category verdicts
        $verdicts = $this->verdictService->computeAllVerdicts($indicators);

        // 5. National references
        $nationalRefs = $this->getNationalReferences($activeIndicators);

        // 6. Nearby schools
        $schools = $this->getSchools($report->lat, $report->lng);

        // 7. Proximity factors
        $proximity = $this->proximityService->scoreCached((float) $report->lat, (float) $report->lng);
        $proximityFactors = $proximity->toArray();

        // 7b. User preferences and reachability rings
        $preferences = $report->preferences ?? [];
        $urbanityTier = $deso->urbanity_tier ?? 'semi_urban';
        $reachabilityRings = null;
        $isochrone = null;
        $isochroneMode = null;

        if (config('proximity.isochrone.enabled')) {
            // Generate personalized reachability rings if preferences exist
            if (! empty($preferences)) {
                $walkingMinutes = $preferences['walking_distance_minutes']
                    ?? config('questionnaire.default_walking_distance', 15);
                $hasCar = $preferences['has_car'] ?? null;

                $ringResult = $this->isochroneService->generateMultipleRings(
                    (float) $report->lat,
                    (float) $report->lng,
                    $urbanityTier,
                    $walkingMinutes,
                    $hasCar,
                );

                if ($ringResult) {
                    $reachabilityRings = $ringResult['rings'];
                    $isochrone = $ringResult['geojson'];
                    // Determine primary mode from the most common ring mode
                    $modes = array_column($reachabilityRings, 'mode');
                    $isochroneMode = count(array_filter($modes, fn ($m) => $m === 'auto')) > count($modes) / 2
                        ? 'auto'
                        : 'pedestrian';
                }
            }

            // Fall back to default isochrone if no rings generated
            if (! $isochrone) {
                $isochroneMode = config("proximity.isochrone.costing.{$urbanityTier}", 'pedestrian');
                $contours = config("proximity.isochrone.display_contours.{$urbanityTier}", [5, 10, 15]);
                $isochrone = $this->isochroneService->generate(
                    (float) $report->lat,
                    (float) $report->lng,
                    $isochroneMode,
                    $contours,
                );
            }
        }

        // 8. Map snapshot data
        $mapSnapshot = $this->getMapSnapshot($deso->deso_code, (float) $report->lat, (float) $report->lng, $schools);

        // 9. Strengths & weaknesses
        $strengths = $this->generateStrengths($indicators, $schools);
        $weaknesses = $this->generateWeaknesses($indicators);

        // 10. Outlook
        $outlook = $this->generateOutlook($scoreHistory, $verdicts);

        // 11. Compute default & personalized scores
        $defaultScore = $latestScore ? round((float) $latestScore->score, 2) : null;
        $trend1y = $latestScore && $latestScore->trend_1y ? round((float) $latestScore->trend_1y, 2) : null;

        // Compute personalized score if preferences exist
        $personalizedResult = $this->personalizedScoringService->compute(
            $defaultScore,
            $indicators,
            $proximityFactors,
            $preferences,
        );
        $personalizedScore = $personalizedResult['score'] ?? $defaultScore;

        // Latest year from score data
        $year = $latestScore ? (int) $latestScore->year : null;

        $report->update([
            'area_indicators' => $indicators,
            'proximity_factors' => $proximityFactors,
            'schools' => $schools,
            'category_verdicts' => $verdicts,
            'score_history' => $scoreHistory,
            'deso_meta' => $desoMeta,
            'national_references' => $nationalRefs,
            'map_snapshot' => $mapSnapshot,
            'outlook' => $outlook,
            'top_positive' => $strengths,
            'top_negative' => $weaknesses,
            'default_score' => $defaultScore,
            'personalized_score' => $personalizedScore,
            'trend_1y' => $trend1y,
            'model_version' => self::MODEL_VERSION,
            'indicator_count' => count($indicators),
            'year' => $year,
            'isochrone' => $isochrone,
            'isochrone_mode' => $isochroneMode,
            'reachability_rings' => $reachabilityRings,
        ]);

        return $report->fresh();
    }

    /**
     * @param  Collection<int, IndicatorValue>  $history
     * @return array<string, mixed>
     */
    private function buildIndicatorSnapshot(Indicator $indicator, Collection $history, IndicatorValue $current): array
    {
        $currentYear = (int) $current->year;
        $currentPercentile = (int) round((float) $current->normalized_value * 100);

        $prevYear = $history->firstWhere('year', $currentYear - 1);
        $prevPercentile = $prevYear ? (int) round((float) $prevYear->normalized_value * 100) : null;

        $rawValue = (float) $current->raw_value;

        return [
            'slug' => $indicator->slug,
            'name' => $indicator->name,
            'category' => $indicator->category,
            'source' => $indicator->source,
            'unit' => $indicator->unit,
            'direction' => $indicator->direction,
            'raw_value' => $rawValue,
            'formatted_value' => $this->formatValue($rawValue, $indicator->unit),
            'normalized_value' => round((float) $current->normalized_value, 4),
            'percentile' => $currentPercentile,
            'description' => $indicator->description_short ?? $indicator->description,
            'trend' => [
                'years' => $history->pluck('year')->map(fn ($y) => (int) $y)->values()->toArray(),
                'percentiles' => $history->pluck('normalized_value')
                    ->map(fn ($v) => (int) round((float) $v * 100))->values()->toArray(),
                'raw_values' => $history->pluck('raw_value')
                    ->map(fn ($v) => (float) $v)->values()->toArray(),
                'change_1y' => $prevPercentile !== null ? $currentPercentile - $prevPercentile : null,
                'change_3y' => $this->percentileChange($history, $currentYear, 3),
                'change_5y' => $this->percentileChange($history, $currentYear, 5),
            ],
        ];
    }

    /**
     * @param  Collection<int, IndicatorValue>  $history
     */
    private function percentileChange(Collection $history, int $currentYear, int $span): ?int
    {
        $current = $history->firstWhere('year', $currentYear);
        $past = $history->firstWhere('year', $currentYear - $span);

        if (! $current || ! $past) {
            return null;
        }

        return (int) (round((float) $current->normalized_value * 100) - round((float) $past->normalized_value * 100));
    }

    private function formatValue(float $value, ?string $unit): string
    {
        return match ($unit) {
            'SEK' => number_format((int) $value, 0, ',', "\u{00A0}").' kr',
            'percent', '%' => number_format($value, 1, ',', '')."\u{00A0}%",
            'per_1000', '/1000' => number_format($value, 1, ',', '').'/1 000',
            'per_100k', '/100k' => number_format($value, 1, ',', '').'/100k',
            'points' => (string) (int) $value,
            default => number_format($value, 1, ',', ''),
        };
    }

    /**
     * @param  Collection<int, Indicator>  $activeIndicators
     * @return array<string, array{median: float|null, formatted: string|null}>
     */
    private function getNationalReferences(Collection $activeIndicators): array
    {
        $refs = [];

        foreach ($activeIndicators as $indicator) {
            if (in_array($indicator->slug, self::AMENITY_DENSITY_SLUGS, true)) {
                continue;
            }

            $latestYear = IndicatorValue::where('indicator_id', $indicator->id)
                ->whereNotNull('raw_value')
                ->max('year');

            if (! $latestYear) {
                $refs[$indicator->slug] = ['median' => null, 'formatted' => null];

                continue;
            }

            $median = DB::selectOne('
                SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY raw_value) as median
                FROM indicator_values
                WHERE indicator_id = ? AND year = ? AND raw_value IS NOT NULL
            ', [$indicator->id, $latestYear])?->median;

            $medianFloat = $median !== null ? round((float) $median, 2) : null;

            $refs[$indicator->slug] = [
                'median' => $medianFloat,
                'formatted' => $medianFloat !== null ? $this->formatValue($medianFloat, $indicator->unit) : null,
            ];
        }

        return $refs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSchools(string|float $lat, string|float $lng): array
    {
        $lat = (float) $lat;
        $lng = (float) $lng;

        $rows = DB::select('
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
                  2000
              )
            ORDER BY distance_m
            LIMIT 10
        ', [$lng, $lat, $lng, $lat]);

        return collect($rows)->map(fn ($s) => [
            'name' => $s->name,
            'type' => $s->type_of_schooling,
            'operator_type' => $s->operator_type,
            'distance_m' => (int) round((float) $s->distance_m),
            'merit_value' => $s->merit_value_17 ? (float) $s->merit_value_17 : null,
            'goal_achievement' => $s->goal_achievement_pct ? (float) $s->goal_achievement_pct : null,
            'teacher_certification' => $s->teacher_certification_pct ? (float) $s->teacher_certification_pct : null,
            'student_count' => $s->student_count,
            'lat' => (float) $s->lat,
            'lng' => (float) $s->lng,
        ])->toArray();
    }

    /**
     * @param  array<int, array<string, mixed>>  $schools
     * @return array<string, mixed>
     */
    private function getMapSnapshot(string $desoCode, float $lat, float $lng, array $schools): array
    {
        $desoGeo = DB::selectOne('
            SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001)) as geojson
            FROM deso_areas WHERE deso_code = ?
        ', [$desoCode]);

        $surrounding = DB::select('
            SELECT d2.deso_code,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d2.geom, 0.0002)) as geojson
            FROM deso_areas d1
            JOIN deso_areas d2 ON ST_DWithin(d1.geom, d2.geom, 0.005)
            WHERE d1.deso_code = ? AND d2.deso_code != ?
            LIMIT 20
        ', [$desoCode, $desoCode]);

        return [
            'center' => [$lat, $lng],
            'zoom' => 14,
            'deso_geojson' => $desoGeo ? json_decode($desoGeo->geojson, true) : null,
            'pin' => [$lat, $lng],
            'school_markers' => collect($schools)->map(fn ($s) => [
                'lat' => $s['lat'],
                'lng' => $s['lng'],
                'name' => $s['name'],
                'merit' => $s['merit_value'],
            ])->toArray(),
            'surrounding_desos' => collect($surrounding)->map(fn ($r) => [
                'deso_code' => $r->deso_code,
                'geojson' => json_decode($r->geojson, true),
            ])->toArray(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $indicators
     * @param  array<int, array<string, mixed>>  $schools
     * @return array<int, array<string, mixed>>
     */
    private function generateStrengths(array $indicators, array $schools): array
    {
        $strengths = [];

        foreach ($indicators as $ind) {
            if (($ind['direction'] ?? 'neutral') === 'neutral') {
                continue;
            }

            $directedPctl = $ind['direction'] === 'negative'
                ? 100 - $ind['percentile']
                : $ind['percentile'];

            if ($directedPctl >= 75) {
                $strengths[] = [
                    'category' => $ind['category'],
                    'slug' => $ind['slug'],
                    'text_sv' => $this->strengthText($ind),
                    'percentile' => $directedPctl,
                ];
            }
        }

        // High-quality nearby school
        $topSchool = collect($schools)
            ->filter(fn ($s) => ($s['merit_value'] ?? 0) >= 230)
            ->sortBy('distance_m')
            ->first();

        if ($topSchool) {
            $strengths[] = [
                'category' => 'education',
                'slug' => 'school_nearby',
                'text_sv' => "Hög skolkvalitet i närheten \u{2014} {$topSchool['name']} ({$topSchool['merit_value']} meritvärde), bara {$topSchool['distance_m']} m bort.",
                'percentile' => 90,
            ];
        }

        usort($strengths, fn ($a, $b) => $b['percentile'] <=> $a['percentile']);

        return array_slice($strengths, 0, 5);
    }

    /**
     * @param  array<int, array<string, mixed>>  $indicators
     * @return array<int, array<string, mixed>>
     */
    private function generateWeaknesses(array $indicators): array
    {
        $weaknesses = [];

        foreach ($indicators as $ind) {
            if (($ind['direction'] ?? 'neutral') === 'neutral') {
                continue;
            }

            $directedPctl = $ind['direction'] === 'negative'
                ? 100 - $ind['percentile']
                : $ind['percentile'];

            if ($directedPctl <= 35) {
                $weaknesses[] = [
                    'category' => $ind['category'],
                    'slug' => $ind['slug'],
                    'text_sv' => $this->weaknessText($ind),
                    'percentile' => $directedPctl,
                ];
            }
        }

        usort($weaknesses, fn ($a, $b) => $a['percentile'] <=> $b['percentile']);

        return array_slice($weaknesses, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $ind
     */
    private function strengthText(array $ind): string
    {
        return "{$ind['name']} rankas i {$ind['percentile']}:e percentilen ({$ind['formatted_value']}).";
    }

    /**
     * @param  array<string, mixed>  $ind
     */
    private function weaknessText(array $ind): string
    {
        $pctl = $ind['direction'] === 'negative' ? 100 - $ind['percentile'] : $ind['percentile'];

        return "{$ind['name']} rankas i {$pctl}:e percentilen ({$ind['formatted_value']}).";
    }

    /**
     * @param  array<int, array{year: int, score: float}>  $scoreHistory
     * @param  array<string, array<string, mixed>>  $verdicts
     * @return array<string, mixed>
     */
    private function generateOutlook(array $scoreHistory, array $verdicts): array
    {
        $years = count($scoreHistory);
        $firstScore = $scoreHistory[0]['score'] ?? null;
        $lastScore = ! empty($scoreHistory) ? $scoreHistory[count($scoreHistory) - 1]['score'] : null;
        $totalChange = $lastScore !== null && $firstScore !== null ? $lastScore - $firstScore : null;

        $improving = collect($verdicts)->filter(fn ($v) => ($v['trend_direction'] ?? '') === 'improving')->count();
        $declining = collect($verdicts)->filter(fn ($v) => ($v['trend_direction'] ?? '') === 'declining')->count();
        $total = collect($verdicts)->filter(fn ($v) => ($v['score'] ?? null) !== null)->count();

        $outlook = match (true) {
            $improving >= 3 && $declining === 0 => 'strong_positive',
            $improving >= 2 => 'positive',
            $declining >= 3 => 'negative',
            $declining >= 2 => 'cautious',
            default => 'neutral',
        };

        $outlookLabel = match ($outlook) {
            'strong_positive' => 'Starkt positiv',
            'positive' => 'Positiv',
            'neutral' => 'Neutral',
            'cautious' => 'Viss osäkerhet',
            'negative' => 'Utmanande',
        };

        $text = $this->generateOutlookText($scoreHistory, $outlook, $improving, $declining, $total, $totalChange);

        return [
            'outlook' => $outlook,
            'outlook_label' => $outlookLabel,
            'total_change' => $totalChange !== null ? round($totalChange, 1) : null,
            'years_span' => $years,
            'improving_count' => $improving,
            'declining_count' => $declining,
            'total_categories' => $total,
            'text_sv' => $text,
            'disclaimer' => 'Detta är en statistisk uppskattning baserad på historiska data. Inte finansiell rådgivning. Lokala faktorer som nybyggnation, infrastrukturprojekt och politiska beslut kan påverka utvecklingen avsevärt.',
        ];
    }

    /**
     * @param  array<int, array{year: int, score: float}>  $scoreHistory
     */
    private function generateOutlookText(
        array $scoreHistory,
        string $outlook,
        int $improving,
        int $declining,
        int $total,
        ?float $totalChange
    ): string {
        $parts = [];

        if (count($scoreHistory) >= 2 && $totalChange !== null) {
            $first = $scoreHistory[0];
            $last = $scoreHistory[count($scoreHistory) - 1];
            $direction = $totalChange > 0 ? 'stigit' : ($totalChange < 0 ? 'sjunkit' : 'legat stabilt');
            $change = abs(round($totalChange, 1));
            $parts[] = "Områdets totalpoäng har {$direction} från {$first['score']} ({$first['year']}) till {$last['score']} ({$last['year']}), en förändring på {$change} poäng.";
        }

        if ($total > 0) {
            $parts[] = "{$improving} av {$total} kategorier visar förbättring senaste året.";
        }

        $outlookText = match ($outlook) {
            'strong_positive' => 'Baserat på historiska mönster tyder områdets profil på fortsatt positiv utveckling.',
            'positive' => 'Utvecklingen pekar i övervägande positiv riktning.',
            'neutral' => 'Utvecklingen är stabil utan tydlig riktning.',
            'cautious' => 'Vissa indikatorer visar nedåtgående trend, vilket motiverar uppmärksamhet.',
            'negative' => 'Flera indikatorer visar nedåtgående trend.',
        };
        $parts[] = $outlookText;

        return implode(' ', $parts);
    }
}
