<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePenaltyRequest;
use App\Models\ScorePenalty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminPenaltyController extends Controller
{
    public function index(): Response
    {
        $penalties = ScorePenalty::query()
            ->orderBy('category')
            ->orderBy('display_order')
            ->get();

        $affectedCounts = DB::table('deso_vulnerability_mapping')
            ->where('overlap_fraction', '>=', 0.10)
            ->select('tier', DB::raw('COUNT(DISTINCT deso_code) as deso_count'))
            ->groupBy('tier')
            ->pluck('deso_count', 'tier');

        $affectedPopulation = DB::table('deso_vulnerability_mapping as dvm')
            ->join('deso_areas as da', 'da.deso_code', '=', 'dvm.deso_code')
            ->where('dvm.overlap_fraction', '>=', 0.10)
            ->select('dvm.tier', DB::raw('COALESCE(SUM(da.population), 0) as pop'))
            ->groupBy('dvm.tier')
            ->pluck('pop', 'tier');

        return Inertia::render('admin/penalties', [
            'penalties' => $penalties->map(fn (ScorePenalty $p) => [
                ...$p->toArray(),
                'affected_desos' => $affectedCounts[$this->tierFromSlug($p->slug)] ?? 0,
                'affected_population' => $affectedPopulation[$this->tierFromSlug($p->slug)] ?? 0,
            ]),
        ]);
    }

    public function update(UpdatePenaltyRequest $request, ScorePenalty $penalty): RedirectResponse
    {
        $penalty->update($request->validated());

        return back()->with('success', 'Avdrag uppdaterat. Beräkna om poäng för att tillämpa ändringarna.');
    }

    private function tierFromSlug(string $slug): ?string
    {
        return match ($slug) {
            'vuln_sarskilt_utsatt' => 'sarskilt_utsatt',
            'vuln_utsatt' => 'utsatt',
            default => null,
        };
    }
}
