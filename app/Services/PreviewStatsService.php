<?php

namespace App\Services;

use App\Models\Indicator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PreviewStatsService
{
    /**
     * Returns cached data scale stats for the teaser categories.
     *
     * @return array<string, array{stat_line: string, indicator_count: int}>
     */
    public function getStats(): array
    {
        return Cache::remember('preview_teaser_stats', 86400, function () {
            return [
                'safety' => $this->safetyStats(),
                'economy' => $this->economyStats(),
                'education' => $this->educationStats(),
            ];
        });
    }

    /**
     * Location-specific proximity stat (not cached — single indexed query).
     *
     * @return array{stat_line: string, indicator_count: int, poi_count: int}
     */
    public function proximityStats(float $lat, float $lng): array
    {
        $nearbyPoiCount = (int) DB::selectOne('
            SELECT COUNT(*) as count FROM pois
            WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 2000)
        ', [$lng, $lat])->count;

        $indicatorCount = $this->countActiveIndicators('proximity');

        $statLine = $nearbyPoiCount > 0
            ? 'Vi analyserade '.number_format($nearbyPoiCount, 0, ',', "\u{00A0}").' servicepunkter inom 2 km — kollektivtrafik, grönområden, mataffärer och mer.'
            : 'Platsspecifik närhetsanalys av kollektivtrafik, grönområden, mataffärer och mer.';

        return [
            'stat_line' => $statLine,
            'indicator_count' => $indicatorCount,
            'poi_count' => $nearbyPoiCount,
        ];
    }

    /**
     * @return array{stat_line: string, indicator_count: int}
     */
    private function safetyStats(): array
    {
        $vulnerableAreas = (int) DB::table('vulnerability_areas')->count();

        $crimeStatCount = (int) DB::table('crime_statistics')->count();

        $indicatorCount = $this->countActiveIndicators('safety');

        if ($crimeStatCount > 0) {
            $statLine = 'Trygghetsbetyg baserat på brottsstatistik från 290 kommuner och polisens klassificering av '.$vulnerableAreas.' utsatta områden.';
        } else {
            $statLine = 'Trygghetsbetyg baserat på polisens klassificering av '.$vulnerableAreas.' utsatta områden.';
        }

        return [
            'stat_line' => $statLine,
            'indicator_count' => $indicatorCount,
        ];
    }

    /**
     * @return array{stat_line: string, indicator_count: int}
     */
    private function economyStats(): array
    {
        $indicatorCount = $this->countActiveIndicators('economy');

        return [
            'stat_line' => "Ekonomisk analys från {$indicatorCount} indikatorer — inkomst, sysselsättning, skuldsättning och ekonomisk standard.",
            'indicator_count' => $indicatorCount,
        ];
    }

    /**
     * @return array{stat_line: string, indicator_count: int}
     */
    private function educationStats(): array
    {
        $schoolCount = (int) DB::table('schools')
            ->where('status', 'active')
            ->count();

        $indicatorCount = $this->countActiveIndicators('education');

        return [
            'stat_line' => 'Skolanalys baserad på '.number_format($schoolCount, 0, ',', "\u{00A0}").' skolor med meritvärden, måluppfyllelse och lärarbehörighet.',
            'indicator_count' => $indicatorCount,
        ];
    }

    private function countActiveIndicators(string $categoryKey): int
    {
        $slugs = config("indicator_categories.{$categoryKey}.indicators", []);

        return Indicator::where('is_active', true)
            ->whereIn('slug', $slugs)
            ->count();
    }
}
