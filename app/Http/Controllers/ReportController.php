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
                'score' => $report->score ? (float) $report->score : null,
                'score_label' => $this->scoreLabel($report->score ? (float) $report->score : null),
                'created_at' => $report->created_at->toISOString(),
                'view_count' => $report->view_count,
                'lat' => (float) $report->lat,
                'lng' => (float) $report->lng,
            ],
        ]);
    }

    private function scoreLabel(?float $score): string
    {
        if ($score === null) {
            return 'Ingen data';
        }

        return match (true) {
            $score >= 80 => 'Starkt tillväxtområde',
            $score >= 60 => 'Stabilt / Positivt',
            $score >= 40 => 'Blandat',
            $score >= 20 => 'Förhöjd risk',
            default => 'Hög risk',
        };
    }
}
