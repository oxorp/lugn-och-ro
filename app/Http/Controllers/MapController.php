<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(): Response
    {
        $indicators = Indicator::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $indicatorScopes = $indicators->pluck('normalization_scope', 'slug');

        $indicatorMeta = $indicators->mapWithKeys(fn (Indicator $ind) => [
            $ind->slug => [
                'name' => $ind->name,
                'description_short' => $ind->description_short,
                'description_long' => $ind->description_long,
                'methodology_note' => $ind->methodology_note,
                'national_context' => $ind->national_context,
                'source_name' => $ind->source_name,
                'source_url' => $ind->source_url,
                'update_frequency' => $ind->update_frequency,
                'data_vintage' => $ind->data_vintage,
                'data_last_ingested_at' => $ind->last_ingested_at?->toIso8601String(),
                'unit' => $ind->unit,
                'direction' => $ind->direction,
                'category' => $ind->category,
            ],
        ]);

        return Inertia::render('map', [
            'initialCenter' => [62.0, 15.0],
            'initialZoom' => 5,
            'indicatorScopes' => $indicatorScopes,
            'indicatorMeta' => $indicatorMeta,
        ]);
    }
}
