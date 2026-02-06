<?php

use App\Http\Controllers\DesoController;
use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [MapController::class, 'index'])->name('map');

Route::get('/api/deso/geojson', [DesoController::class, 'geojson'])->name('deso.geojson');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
