<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('map', [
            'initialCenter' => [62.0, 15.0],
            'initialZoom' => 5,
        ]);
    }
}
