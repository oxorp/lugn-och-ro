<?php

namespace App\Http\Controllers;

use App\Services\NormalizationService;
use App\Services\ScoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminScoreController extends Controller
{
    public function recompute(NormalizationService $normalization, ScoringService $scoring): RedirectResponse
    {
        $years = DB::table('indicator_values')
            ->distinct()
            ->pluck('year')
            ->toArray();

        $totalScored = 0;

        foreach ($years as $year) {
            $normalization->normalizeAll($year);
            $totalScored += $scoring->computeScores($year);
        }

        return back()->with('success', "Recomputed scores for {$totalScored} DeSO areas");
    }
}
