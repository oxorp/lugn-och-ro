<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(): Response
    {
        $indicatorScopes = Indicator::query()
            ->where('is_active', true)
            ->pluck('normalization_scope', 'slug');

        return Inertia::render('map', [
            'initialCenter' => [62.0, 15.0],
            'initialZoom' => 5,
            'indicatorScopes' => $indicatorScopes,
        ]);
    }
}
