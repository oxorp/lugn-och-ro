<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeocodeController extends Controller
{
    public function resolveDeso(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => 'required|numeric|between:55,70',
            'lng' => 'required|numeric|between:10,25',
        ]);

        $lat = $request->float('lat');
        $lng = $request->float('lng');

        $deso = DB::table('deso_areas')
            ->select('deso_code', 'deso_name', 'kommun_name', 'lan_name')
            ->whereRaw('ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$lng, $lat])
            ->first();

        if (! $deso) {
            // Point might be slightly outside DeSO boundaries (coast, border)
            // Try nearest DeSO within 500m
            $deso = DB::table('deso_areas')
                ->select('deso_code', 'deso_name', 'kommun_name', 'lan_name')
                ->whereRaw('ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 500)', [$lng, $lat])
                ->orderByRaw('ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)', [$lng, $lat])
                ->first();
        }

        return response()->json([
            'deso' => $deso ? [
                'deso_code' => $deso->deso_code,
                'deso_name' => $deso->deso_name,
                'kommun_name' => $deso->kommun_name,
                'lan_name' => $deso->lan_name,
            ] : null,
        ]);
    }
}
