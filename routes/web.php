<?php

use App\Http\Controllers\AdminDataQualityController;
use App\Http\Controllers\AdminIndicatorController;
use App\Http\Controllers\AdminPipelineController;
use App\Http\Controllers\AdminScoreController;
use App\Http\Controllers\CompareController;
use App\Http\Controllers\DesoController;
use App\Http\Controllers\GeocodeController;
use App\Http\Controllers\H3Controller;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// API routes — no locale prefix, no locale middleware
Route::get('/api/deso/geojson', [DesoController::class, 'geojson'])->name('deso.geojson');
Route::get('/api/deso/scores', [DesoController::class, 'scores'])->name('deso.scores');
Route::get('/api/deso/{desoCode}/schools', [DesoController::class, 'schools'])->name('deso.schools');
Route::get('/api/deso/{desoCode}/crime', [DesoController::class, 'crime'])->name('deso.crime');
Route::get('/api/deso/{desoCode}/financial', [DesoController::class, 'financial'])->name('deso.financial');
Route::get('/api/deso/{desoCode}/pois', [DesoController::class, 'pois'])->name('deso.pois');
Route::get('/api/deso/{desoCode}/indicators', [DesoController::class, 'indicators'])->name('deso.indicators');

Route::get('/api/geocode/resolve-deso', [GeocodeController::class, 'resolveDeso'])->name('geocode.resolve-deso');

Route::post('/api/compare', [CompareController::class, 'compare'])->name('api.compare');

Route::get('/api/h3/scores', [H3Controller::class, 'scores'])->name('h3.scores');
Route::get('/api/h3/viewport', [H3Controller::class, 'viewport'])->name('h3.viewport');
Route::get('/api/h3/smoothing-configs', [H3Controller::class, 'smoothingConfigs'])->name('h3.smoothing-configs');

// Shared route definitions (used for both Swedish and English)
$webRoutes = function () {
    Route::get('/', [MapController::class, 'index'])->name('map');
    Route::get('/methodology', [PageController::class, 'methodology'])->name('methodology');

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

        Route::get('/pipeline', [AdminPipelineController::class, 'index'])->name('admin.pipeline');
        Route::get('/pipeline/logs/{log}', [AdminPipelineController::class, 'log'])->name('admin.pipeline.log');
        Route::get('/pipeline/{source}', [AdminPipelineController::class, 'show'])->name('admin.pipeline.show');
        Route::post('/pipeline/{source}/run', [AdminPipelineController::class, 'run'])->name('admin.pipeline.run');
        Route::post('/pipeline/run-all', [AdminPipelineController::class, 'runAll'])->name('admin.pipeline.run-all');
    });
};

// English routes — /en/ prefix
Route::prefix('en')->middleware('set-locale:en')->as('en.')->group($webRoutes);

// Swedish routes — no prefix (default)
Route::middleware('set-locale:sv')->group($webRoutes);

require __DIR__.'/settings.php';
