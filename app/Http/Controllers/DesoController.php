<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DesoController extends Controller
{
    public function geojson(): JsonResponse|BinaryFileResponse
    {
        $staticPath = public_path('data/deso.geojson');

        if (file_exists($staticPath)) {
            return response()->file($staticPath, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        // Fallback: generate from DB if static file doesn't exist
        $features = DB::select('
            SELECT
                deso_code,
                deso_name,
                kommun_code,
                kommun_name,
                lan_code,
                lan_name,
                area_km2,
                ST_AsGeoJSON(ST_Buffer(geom, 0.00005)) as geometry
            FROM deso_areas
            WHERE geom IS NOT NULL
        ');

        $geojson = [
            'type' => 'FeatureCollection',
            'features' => collect($features)->map(fn ($f) => [
                'type' => 'Feature',
                'geometry' => json_decode($f->geometry),
                'properties' => [
                    'deso_code' => $f->deso_code,
                    'deso_name' => $f->deso_name,
                    'kommun_code' => $f->kommun_code,
                    'kommun_name' => $f->kommun_name,
                    'lan_code' => $f->lan_code,
                    'lan_name' => $f->lan_name,
                    'area_km2' => $f->area_km2,
                ],
            ])->all(),
        ];

        return response()->json($geojson)
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function scores(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $scores = DB::table('composite_scores')
            ->where('year', $year)
            ->select('deso_code', 'score', 'trend_1y', 'factor_scores', 'top_positive', 'top_negative')
            ->get()
            ->keyBy('deso_code');

        return response()->json($scores)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function schools(string $desoCode): JsonResponse
    {
        $schools = School::query()
            ->where('deso_code', $desoCode)
            ->where('status', 'active')
            ->with('latestStatistics')
            ->get()
            ->map(fn (School $school) => [
                'school_unit_code' => $school->school_unit_code,
                'name' => $school->name,
                'type' => $school->type_of_schooling,
                'operator_type' => $school->operator_type,
                'lat' => $school->lat ? (float) $school->lat : null,
                'lng' => $school->lng ? (float) $school->lng : null,
                'merit_value' => $school->latestStatistics?->merit_value_17 ? (float) $school->latestStatistics->merit_value_17 : null,
                'goal_achievement' => $school->latestStatistics?->goal_achievement_pct ? (float) $school->latestStatistics->goal_achievement_pct : null,
                'teacher_certification' => $school->latestStatistics?->teacher_certification_pct ? (float) $school->latestStatistics->teacher_certification_pct : null,
                'student_count' => $school->latestStatistics?->student_count,
            ]);

        return response()->json($schools);
    }
}
