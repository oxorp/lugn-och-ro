<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminDataCompletenessController extends Controller
{
    public function index(): Response
    {
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->orderBy('source')
            ->orderBy('category')
            ->orderBy('display_order')
            ->get();

        $years = range(2019, (int) date('Y'));
        $totalDesos = DB::table('deso_areas')->count();

        $matrix = [];
        foreach ($indicators as $indicator) {
            $yearData = DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->whereIn('year', $years)
                ->groupBy('year')
                ->select(
                    'year',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('COUNT(CASE WHEN raw_value IS NOT NULL THEN 1 END) as non_null_count'),
                    DB::raw('ROUND(AVG(raw_value)::numeric, 2) as avg_value'),
                    DB::raw('MIN(updated_at) as earliest_update'),
                    DB::raw('MAX(updated_at) as latest_update')
                )
                ->get()
                ->keyBy('year');

            $matrix[] = [
                'indicator' => [
                    'id' => $indicator->id,
                    'slug' => $indicator->slug,
                    'name' => $indicator->name,
                    'source' => $indicator->source,
                    'category' => $indicator->category,
                    'unit' => $indicator->unit,
                ],
                'years' => collect($years)->mapWithKeys(function (int $year) use ($yearData, $totalDesos) {
                    $data = $yearData->get($year);

                    return [$year => [
                        'has_data' => $data !== null && $data->non_null_count > 0,
                        'count' => $data->non_null_count ?? 0,
                        'total' => $totalDesos,
                        'coverage_pct' => $data
                            ? round($data->non_null_count / $totalDesos * 100, 1)
                            : 0,
                        'avg_value' => $data->avg_value ?? null,
                        'last_updated' => $data->latest_update ?? null,
                    ]];
                }),
            ];
        }

        $summary = [
            'total_indicators' => count($indicators),
            'total_years' => count($years),
            'total_cells' => count($indicators) * count($years),
            'filled_cells' => collect($matrix)->sum(fn (array $row) => collect($row['years'])->filter(fn (array $y) => $y['has_data'])->count()
            ),
            'total_desos' => $totalDesos,
        ];

        return Inertia::render('admin/data-completeness', [
            'matrix' => $matrix,
            'years' => $years,
            'summary' => $summary,
        ]);
    }
}
