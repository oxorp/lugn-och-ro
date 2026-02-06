<?php

use App\Http\Controllers\AdminIndicatorController;
use App\Http\Controllers\AdminScoreController;
use App\Http\Controllers\DesoController;
use App\Http\Controllers\MapController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [MapController::class, 'index'])->name('map');

Route::get('/api/deso/geojson', [DesoController::class, 'geojson'])->name('deso.geojson');
Route::get('/api/deso/scores', [DesoController::class, 'scores'])->name('deso.scores');
Route::get('/api/deso/{desoCode}/schools', [DesoController::class, 'schools'])->name('deso.schools');
Route::get('/api/deso/{desoCode}/crime', [DesoController::class, 'crime'])->name('deso.crime');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

Route::prefix('admin')->group(function () {
    Route::get('/indicators', [AdminIndicatorController::class, 'index'])->name('admin.indicators');
    Route::put('/indicators/{indicator}', [AdminIndicatorController::class, 'update'])->name('admin.indicators.update');
    Route::post('/recompute-scores', [AdminScoreController::class, 'recompute'])->name('admin.recompute');
});

require __DIR__.'/settings.php';
