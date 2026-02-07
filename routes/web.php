<?php

use App\Http\Controllers\AdminDataQualityController;
use App\Http\Controllers\AdminIndicatorController;
use App\Http\Controllers\AdminScoreController;
use App\Http\Controllers\DesoController;
use App\Http\Controllers\H3Controller;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [MapController::class, 'index'])->name('map');
Route::get('/methodology', [PageController::class, 'methodology'])->name('methodology');

Route::get('/api/deso/geojson', [DesoController::class, 'geojson'])->name('deso.geojson');
Route::get('/api/deso/scores', [DesoController::class, 'scores'])->name('deso.scores');
Route::get('/api/deso/{desoCode}/schools', [DesoController::class, 'schools'])->name('deso.schools');
Route::get('/api/deso/{desoCode}/crime', [DesoController::class, 'crime'])->name('deso.crime');
Route::get('/api/deso/{desoCode}/financial', [DesoController::class, 'financial'])->name('deso.financial');
Route::get('/api/deso/{desoCode}/pois', [DesoController::class, 'pois'])->name('deso.pois');

Route::get('/api/h3/scores', [H3Controller::class, 'scores'])->name('h3.scores');
Route::get('/api/h3/viewport', [H3Controller::class, 'viewport'])->name('h3.viewport');
Route::get('/api/h3/smoothing-configs', [H3Controller::class, 'smoothingConfigs'])->name('h3.smoothing-configs');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

Route::prefix('admin')->group(function () {
    Route::get('/indicators', [AdminIndicatorController::class, 'index'])->name('admin.indicators');
    Route::put('/indicators/{indicator}', [AdminIndicatorController::class, 'update'])->name('admin.indicators.update');
    Route::post('/recompute-scores', [AdminScoreController::class, 'recompute'])->name('admin.recompute');

    Route::get('/data-quality', [AdminDataQualityController::class, 'index'])->name('admin.data-quality');
    Route::post('/data-quality/publish/{versionId}', [AdminDataQualityController::class, 'publish'])->name('admin.data-quality.publish');
    Route::post('/data-quality/rollback/{versionId}', [AdminDataQualityController::class, 'rollback'])->name('admin.data-quality.rollback');
});

require __DIR__.'/settings.php';
