<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateIndicatorRequest;
use App\Models\Indicator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminIndicatorController extends Controller
{
    public function index(): Response
    {
        $indicators = Indicator::query()
            ->orderBy('display_order')
            ->get()
            ->map(function (Indicator $indicator) {
                $latestYear = DB::table('indicator_values')
                    ->where('indicator_id', $indicator->id)
                    ->max('year');

                $coverage = $latestYear
                    ? DB::table('indicator_values')
                        ->where('indicator_id', $indicator->id)
                        ->where('year', $latestYear)
                        ->whereNotNull('raw_value')
                        ->count()
                    : 0;

                $totalDesos = DB::table('deso_areas')->count();

                return [
                    'id' => $indicator->id,
                    'slug' => $indicator->slug,
                    'name' => $indicator->name,
                    'source' => $indicator->source,
                    'category' => $indicator->category,
                    'direction' => $indicator->direction,
                    'weight' => (float) $indicator->weight,
                    'normalization' => $indicator->normalization,
                    'is_active' => $indicator->is_active,
                    'latest_year' => $latestYear,
                    'coverage' => $coverage,
                    'total_desos' => $totalDesos,
                ];
            });

        return Inertia::render('admin/indicators', [
            'indicators' => $indicators,
        ]);
    }

    public function update(UpdateIndicatorRequest $request, Indicator $indicator): \Illuminate\Http\RedirectResponse
    {
        $indicator->update($request->validated());

        return back()->with('success', "Updated {$indicator->name}");
    }
}
