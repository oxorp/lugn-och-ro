<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompareRequest;
use App\Models\Indicator;
use App\Models\ScoreVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CompareController extends Controller
{
    public function compare(CompareRequest $request): JsonResponse
    {
        $pointA = $request->input('point_a');
        $pointB = $request->input('point_b');
        $year = $request->integer('year', 2024);

        $locationA = $this->resolveLocation($pointA['lat'], $pointA['lng'], $year);
        $locationB = $this->resolveLocation($pointB['lat'], $pointB['lng'], $year);

        // Distance in km
        $distanceKm = $this->calculateDistance($pointA['lat'], $pointA['lng'], $pointB['lat'], $pointB['lng']);

        // Build comparison summary
        $comparison = $this->buildComparison($locationA, $locationB);

        return response()->json([
            'location_a' => $locationA,
            'location_b' => $locationB,
            'distance_km' => round($distanceKm, 2),
            'comparison' => $comparison,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLocation(float $lat, float $lng, int $year): array
    {
        // Resolve DeSO by spatial lookup
        $deso = DB::table('deso_areas')
            ->select('deso_code', 'deso_name', 'kommun_name', 'lan_name', 'urbanity_tier')
            ->whereRaw('ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$lng, $lat])
            ->first();

        if (! $deso) {
            $deso = DB::table('deso_areas')
                ->select('deso_code', 'deso_name', 'kommun_name', 'lan_name', 'urbanity_tier')
                ->whereRaw('ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 500)', [$lng, $lat])
                ->orderByRaw('ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)', [$lng, $lat])
                ->first();
        }

        if (! $deso) {
            return [
                'lat' => $lat,
                'lng' => $lng,
                'deso_code' => null,
                'deso_name' => null,
                'kommun_name' => null,
                'lan_name' => null,
                'urbanity_tier' => null,
                'label' => 'Unknown area',
                'composite_score' => null,
                'score_label' => null,
                'indicators' => [],
            ];
        }

        // Get composite score
        $publishedVersion = ScoreVersion::query()
            ->where('year', $year)
            ->where('status', 'published')
            ->latest('published_at')
            ->first();

        $scoreQuery = DB::table('composite_scores')
            ->where('deso_code', $deso->deso_code)
            ->where('year', $year);

        if ($publishedVersion) {
            $scoreQuery->where('score_version_id', $publishedVersion->id);
        } else {
            $scoreQuery->whereNull('score_version_id');
        }

        $score = $scoreQuery->first();

        // Get all active indicator values
        $activeIndicators = Indicator::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $indicatorValues = DB::table('indicator_values')
            ->where('deso_code', $deso->deso_code)
            ->where('year', $year)
            ->whereIn('indicator_id', $activeIndicators->pluck('id'))
            ->get()
            ->keyBy('indicator_id');

        $indicators = [];
        foreach ($activeIndicators as $ind) {
            $iv = $indicatorValues->get($ind->id);
            if (! $iv || $iv->normalized_value === null) {
                continue;
            }

            $indicators[$ind->slug] = [
                'name' => $ind->name,
                'raw_value' => $iv->raw_value !== null ? round((float) $iv->raw_value, 4) : null,
                'normalized' => round((float) $iv->normalized_value, 6),
                'percentile' => round((float) $iv->normalized_value * 100),
                'unit' => $ind->unit,
                'direction' => $ind->direction,
                'weight' => (float) $ind->weight,
                'normalization_scope' => $ind->normalization_scope,
            ];
        }

        $compositeScore = $score ? round((float) $score->score, 1) : null;

        return [
            'lat' => $lat,
            'lng' => $lng,
            'deso_code' => $deso->deso_code,
            'deso_name' => $deso->deso_name,
            'kommun_name' => $deso->kommun_name,
            'lan_name' => $deso->lan_name,
            'urbanity_tier' => $deso->urbanity_tier,
            'label' => $deso->deso_name ?? $deso->deso_code,
            'composite_score' => $compositeScore,
            'score_label' => $compositeScore !== null ? $this->scoreLabel($compositeScore) : null,
            'indicators' => $indicators,
        ];
    }

    private function calculateDistance(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $result = DB::selectOne('
            SELECT ST_Distance(
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
            ) / 1000 as distance_km
        ', [$lngA, $latA, $lngB, $latB]);

        return (float) $result->distance_km;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function buildComparison(array $a, array $b): array
    {
        $scoreDiff = null;
        if ($a['composite_score'] !== null && $b['composite_score'] !== null) {
            $scoreDiff = round($a['composite_score'] - $b['composite_score'], 1);
        }

        $aStronger = [];
        $bStronger = [];
        $similar = [];

        $allSlugs = array_unique(array_merge(
            array_keys($a['indicators']),
            array_keys($b['indicators']),
        ));

        foreach ($allSlugs as $slug) {
            $aInd = $a['indicators'][$slug] ?? null;
            $bInd = $b['indicators'][$slug] ?? null;

            if (! $aInd || ! $bInd) {
                continue;
            }

            $aPctl = $aInd['percentile'];
            $bPctl = $bInd['percentile'];

            // For negative direction indicators, flip the comparison
            // (higher percentile = worse, so lower is "stronger")
            if ($aInd['direction'] === 'negative') {
                $diff = $bPctl - $aPctl; // A is "stronger" if A has lower percentile
            } else {
                $diff = $aPctl - $bPctl; // A is "stronger" if A has higher percentile
            }

            if (abs($diff) <= 5) {
                $similar[] = $slug;
            } elseif ($diff > 0) {
                $aStronger[] = ['slug' => $slug, 'gap' => abs($diff)];
            } else {
                $bStronger[] = ['slug' => $slug, 'gap' => abs($diff)];
            }
        }

        // Sort by gap size descending
        usort($aStronger, fn ($x, $y) => $y['gap'] <=> $x['gap']);
        usort($bStronger, fn ($x, $y) => $y['gap'] <=> $x['gap']);

        return [
            'score_difference' => $scoreDiff,
            'a_stronger' => array_map(fn ($item) => $item['slug'], $aStronger),
            'b_stronger' => array_map(fn ($item) => $item['slug'], $bStronger),
            'similar' => $similar,
        ];
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
}
