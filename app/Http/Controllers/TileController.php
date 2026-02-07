<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TileController extends Controller
{
    public function serve(int $year, int $z, int $x, int $y): BinaryFileResponse|Response
    {
        $tilePath = storage_path("app/public/tiles/{$year}/{$z}/{$x}/{$y}.png");

        if (! file_exists($tilePath)) {
            // Return a 1x1 transparent PNG for missing tiles
            return response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 200)
                ->header('Content-Type', 'image/png')
                ->header('Cache-Control', 'public, max-age=86400');
        }

        return response()->file($tilePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
