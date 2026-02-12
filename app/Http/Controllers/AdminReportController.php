<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\ReportGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AdminReportController extends Controller
{
    public function __construct(
        private ReportGenerationService $generator,
    ) {}

    /**
     * Create a report for any location, bypassing Stripe.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|min:55|max:69',
            'lng' => 'required|numeric|min:11|max:25',
            'preferences' => 'nullable|array',
            'preferences.priorities' => 'nullable|array',
            'preferences.priorities.*' => 'string',
            'preferences.walking_distance_minutes' => 'nullable|integer|min:1|max:60',
            'preferences.has_car' => 'nullable|boolean',
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        // Look up DeSO + score
        $deso = DB::selectOne('
            SELECT d.deso_code, d.kommun_name, d.lan_name
            FROM deso_areas d
            WHERE ST_Contains(d.geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ', [$lng, $lat]);

        $score = null;
        if ($deso) {
            $score = DB::selectOne('
                SELECT score FROM composite_scores
                WHERE deso_code = ? ORDER BY year DESC LIMIT 1
            ', [$deso->deso_code]);
        }

        $address = $this->reverseGeocode($lat, $lng);

        $report = Report::create([
            'uuid' => Str::uuid(),
            'user_id' => $request->user()->id,
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'kommun_name' => $deso->kommun_name ?? null,
            'lan_name' => $deso->lan_name ?? null,
            'deso_code' => $deso->deso_code ?? null,
            'score' => $score->score ?? null,
            'preferences' => $validated['preferences'] ?? null,
            'amount_ore' => 0,
            'status' => 'completed',
        ]);

        $this->generator->generate($report);

        return redirect()->route('reports.show', $report->uuid);
    }

    private function reverseGeocode(float $lat, float $lng): ?string
    {
        try {
            $response = Http::timeout(3)->get('https://photon.komoot.io/reverse', [
                'lat' => $lat,
                'lon' => $lng,
            ]);
            $props = $response->json('features.0.properties');
            if (! $props) {
                return null;
            }

            return collect([$props['street'] ?? null, $props['housenumber'] ?? null, $props['city'] ?? null])
                ->filter()->implode(', ') ?: null;
        } catch (\Exception) {
            return null;
        }
    }
}
