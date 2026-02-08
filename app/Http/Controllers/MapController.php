<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Services\DataTieringService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function __construct(
        private DataTieringService $tiering,
    ) {}

    public function index(): Response
    {
        $user = Auth::user();
        $tier = $this->tiering->resolveEffectiveTier($user);

        $indicators = Indicator::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        $indicatorScopes = $indicators->pluck('normalization_scope', 'slug');

        $indicatorMeta = $indicators->mapWithKeys(fn (Indicator $ind) => [
            $ind->slug => [
                'name' => $ind->name,
                'description_short' => $ind->description_short,
                'description_long' => $tier->value >= 2 ? $ind->description_long : null,
                'methodology_note' => $tier->value >= 3 ? $ind->methodology_note : null,
                'national_context' => $tier->value >= 3 ? $ind->national_context : null,
                'source_name' => $tier->value >= 2 ? $ind->source_name : null,
                'source_url' => $tier->value >= 3 ? $ind->source_url : null,
                'update_frequency' => $ind->update_frequency,
                'data_vintage' => $tier->value >= 2 ? $ind->data_vintage : null,
                'data_last_ingested_at' => $tier->value >= 3 ? $ind->last_ingested_at?->toIso8601String() : null,
                'unit' => $ind->unit,
                'direction' => $ind->direction,
                'category' => $ind->category,
                // Admin-only metadata
                'source_api_path' => $tier->value >= 99 ? $ind->source_api_path : null,
                'source_field_code' => $tier->value >= 99 ? $ind->source_field_code : null,
                'data_quality_notes' => $tier->value >= 99 ? $ind->data_quality_notes : null,
                'admin_notes' => $tier->value >= 99 ? $ind->admin_notes : null,
                'weight' => $tier->value >= 99 ? (float) $ind->weight : null,
                'normalization_method' => $tier->value >= 99 ? $ind->normalization : null,
            ],
        ]);

        return Inertia::render('explore/map-page', [
            'initialCenter' => [62.0, 15.0],
            'initialZoom' => 5,
            'indicatorScopes' => $indicatorScopes,
            'indicatorMeta' => $indicatorMeta,
            'userTier' => $tier->value,
            'isAuthenticated' => $user !== null,
        ]);
    }
}
