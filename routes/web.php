<?php

use App\Http\Controllers\AdminDataCompletenessController;
use App\Http\Controllers\AdminDataQualityController;
use App\Http\Controllers\AdminIndicatorController;
use App\Http\Controllers\AdminPenaltyController;
use App\Http\Controllers\AdminPipelineController;
use App\Http\Controllers\AdminPoiCategoryController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdminScoreController;
use App\Http\Controllers\DesoController;
use App\Http\Controllers\H3Controller;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MyReportsController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PoiController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TileController;
use App\Http\Controllers\VulnerabilityAreaController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// API routes — no locale prefix, no locale middleware
Route::get('/api/deso/geojson', [DesoController::class, 'geojson'])->name('deso.geojson');
Route::get('/api/deso/scores', [DesoController::class, 'scores'])->name('deso.scores');
Route::middleware('throttle:deso-detail')->group(function () {
    Route::get('/api/deso/{desoCode}/schools', [DesoController::class, 'schools'])->name('deso.schools');
    Route::get('/api/deso/{desoCode}/crime', [DesoController::class, 'crime'])->name('deso.crime');
    Route::get('/api/deso/{desoCode}/financial', [DesoController::class, 'financial'])->name('deso.financial');
    Route::get('/api/deso/{desoCode}/pois', [DesoController::class, 'pois'])->name('deso.pois');
    Route::get('/api/deso/{desoCode}/indicators', [DesoController::class, 'indicators'])->name('deso.indicators');
});

Route::get('/api/h3/scores', [H3Controller::class, 'scores'])->name('h3.scores');
Route::get('/api/h3/viewport', [H3Controller::class, 'viewport'])->name('h3.viewport');
Route::get('/api/h3/smoothing-configs', [H3Controller::class, 'smoothingConfigs'])->name('h3.smoothing-configs');

Route::get('/api/pois', [PoiController::class, 'index'])->name('pois.index');
Route::get('/api/pois/categories', [PoiController::class, 'categories'])->name('pois.categories');

Route::get('/api/location/{lat},{lng}', [LocationController::class, 'show'])
    ->where(['lat' => '[0-9.-]+', 'lng' => '[0-9.-]+'])
    ->name('location.show');

Route::get('/api/vulnerability-areas', [VulnerabilityAreaController::class, 'index'])
    ->name('vulnerability-areas.index');

Route::get('/tiles/{year}/{z}/{x}/{y}.png', [TileController::class, 'serve'])
    ->where(['year' => '[0-9]+', 'z' => '[0-9]+', 'x' => '[0-9]+', 'y' => '[0-9]+'])
    ->name('tiles.serve');

// Google OAuth
Route::get('/auth/google', [SocialAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'callback']);

// Stripe webhook (CSRF excluded via bootstrap/app.php)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->name('stripe.webhook');

// Purchase flow
Route::get('/purchase/{lat},{lng}', [PurchaseController::class, 'show'])
    ->name('purchase')
    ->where(['lat' => '[0-9.]+', 'lng' => '[0-9.]+']);

Route::post('/purchase/checkout', [PurchaseController::class, 'checkout'])
    ->name('purchase.checkout');

Route::get('/purchase/success', [PurchaseController::class, 'success'])
    ->name('purchase.success');

Route::get('/purchase/cancel', [PurchaseController::class, 'cancel'])
    ->name('purchase.cancel');

Route::get('/purchase/status/{sessionId}', [PurchaseController::class, 'status'])
    ->name('purchase.status');

// Reports
Route::get('/reports/{report:uuid}', [ReportController::class, 'show'])
    ->name('reports.show');

Route::get('/my-reports', [MyReportsController::class, 'index'])
    ->name('my-reports');

Route::post('/my-reports/request-access', [MyReportsController::class, 'requestAccess'])
    ->name('my-reports.request-access');

// Shared route definitions (used for both Swedish and English)
$webRoutes = function () {
    Route::get('/', [MapController::class, 'index'])->name('map');
    Route::get('/explore/{lat},{lng}', [MapController::class, 'index'])
        ->where(['lat' => '[0-9.-]+', 'lng' => '[0-9.-]+'])
        ->name('explore');
    Route::get('/methodology', [PageController::class, 'methodology'])->name('methodology');

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('dashboard', function () {
            return Inertia::render('dashboard');
        })->name('dashboard');
    });

    Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
        Route::get('/indicators', [AdminIndicatorController::class, 'index'])->name('admin.indicators');
        Route::put('/indicators/{indicator}', [AdminIndicatorController::class, 'update'])->name('admin.indicators.update');
        Route::put('/poi-categories/{poiCategory}', [AdminIndicatorController::class, 'updatePoiCategory'])->name('admin.poi-categories.update');
        Route::get('/poi-categories', [AdminPoiCategoryController::class, 'index'])->name('admin.poi-categories');
        Route::put('/poi-categories/{poiCategory}/safety', [AdminPoiCategoryController::class, 'update'])->name('admin.poi-categories.update-safety');
        Route::get('/penalties', [AdminPenaltyController::class, 'index'])->name('admin.penalties');
        Route::put('/penalties/{penalty}', [AdminPenaltyController::class, 'update'])->name('admin.penalties.update');
        Route::post('/recompute-scores', [AdminScoreController::class, 'recompute'])->name('admin.recompute');

        Route::get('/data-quality', [AdminDataQualityController::class, 'index'])->name('admin.data-quality');
        Route::post('/data-quality/publish/{versionId}', [AdminDataQualityController::class, 'publish'])->name('admin.data-quality.publish');
        Route::post('/data-quality/rollback/{versionId}', [AdminDataQualityController::class, 'rollback'])->name('admin.data-quality.rollback');

        Route::get('/data-completeness', [AdminDataCompletenessController::class, 'index'])->name('admin.data-completeness');

        Route::get('/pipeline', [AdminPipelineController::class, 'index'])->name('admin.pipeline');
        Route::get('/pipeline/logs/{log}', [AdminPipelineController::class, 'log'])->name('admin.pipeline.log');
        Route::get('/pipeline/{source}', [AdminPipelineController::class, 'show'])->name('admin.pipeline.show');
        Route::post('/pipeline/{source}/run', [AdminPipelineController::class, 'run'])->name('admin.pipeline.run');
        Route::post('/pipeline/run-all', [AdminPipelineController::class, 'runAll'])->name('admin.pipeline.run-all');

        Route::post('/reports/generate', [AdminReportController::class, 'store'])->name('admin.reports.generate');
    });
};

// English routes — /en/ prefix
Route::prefix('en')->middleware('set-locale:en')->as('en.')->group($webRoutes);

// Swedish routes — no prefix (default)
Route::middleware('set-locale:sv')->group($webRoutes);

// Admin "View As" — simulate viewing the app as a different tier
Route::middleware(['auth', 'admin'])->group(function () {
    Route::post('/admin/view-as', function () {
        $tier = request()->integer('tier');
        if ($tier >= 0 && $tier <= 4) {
            session(['viewAs' => $tier]);
        }

        return back();
    })->name('admin.view-as');

    Route::delete('/admin/view-as', function () {
        session()->forget('viewAs');

        return back();
    })->name('admin.view-as.clear');
});

require __DIR__.'/settings.php';
