<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function show(Report $report): Response
    {
        if (! in_array($report->status, ['completed', 'paid'])) {
            abort(404);
        }

        $report->increment('view_count');

        return Inertia::render('reports/show', [
            'report' => [
                'uuid' => $report->uuid,
                'address' => $report->address,
                'kommun_name' => $report->kommun_name,
                'lan_name' => $report->lan_name,
                'deso_code' => $report->deso_code,
                'score' => $report->score ? (float) $report->score : null,
                'score_label' => $report->score_label ?? $this->scoreLabel($report->score ? (float) $report->score : null),
                'created_at' => $report->created_at->toISOString(),
                'view_count' => $report->view_count,
                'lat' => (float) $report->lat,
                'lng' => (float) $report->lng,
                // Snapshot data
                'default_score' => $report->default_score ? (float) $report->default_score : null,
                'personalized_score' => $report->personalized_score ? (float) $report->personalized_score : null,
                'trend_1y' => $report->trend_1y ? (float) $report->trend_1y : null,
                'area_indicators' => $report->area_indicators ?? [],
                'proximity_factors' => $report->proximity_factors,
                'schools' => $report->schools ?? [],
                'category_verdicts' => $report->category_verdicts ?? [],
                'score_history' => $report->score_history ?? [],
                'deso_meta' => $report->deso_meta,
                'national_references' => $report->national_references ?? [],
                'map_snapshot' => $report->map_snapshot,
                'outlook' => $report->outlook,
                'top_positive' => $report->top_positive ?? [],
                'top_negative' => $report->top_negative ?? [],
                'priorities' => $report->priorities ?? [],
                'model_version' => $report->model_version,
                'indicator_count' => $report->indicator_count ?? 0,
                'year' => $report->year,
            ],
        ]);
    }

    private function scoreLabel(?float $score): string
    {
        if ($score === null) {
            return 'Ingen data';
        }

        foreach (config('score_colors.labels') as $label) {
            if ($score >= $label['min'] && $score <= $label['max']) {
                return $label['label_sv'];
            }
        }

        return 'Okänt';
    }
}
