<?php

namespace Tests\Feature;

use App\DataTransferObjects\DriftReport;
use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\IngestionLog;
use App\Models\ScoreVersion;
use App\Models\SentinelArea;
use App\Models\User;
use App\Models\ValidationRule;
use App\Services\DataValidationService;
use App\Services\ScoreDriftDetector;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    // === Validation Rule Engine ===

    public function test_validation_rule_seeder_creates_rules(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->seed(\Database\Seeders\ValidationRuleSeeder::class);

        $this->assertGreaterThan(10, ValidationRule::count());
        $this->assertDatabaseHas('validation_rules', [
            'rule_type' => 'range',
            'severity' => 'error',
        ]);
        $this->assertDatabaseHas('validation_rules', [
            'rule_type' => 'completeness',
            'severity' => 'warning',
        ]);
    }

    public function test_validation_rule_seeder_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->seed(\Database\Seeders\ValidationRuleSeeder::class);
        $count = ValidationRule::count();

        $this->seed(\Database\Seeders\ValidationRuleSeeder::class);
        $this->assertEquals($count, ValidationRule::count());
    }

    public function test_range_validation_passes_valid_data(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Test Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.1,
            'is_active' => true,
        ]);

        ValidationRule::query()->create([
            'indicator_id' => $indicator->id,
            'source' => 'test',
            'rule_type' => 'range',
            'name' => 'Income must be positive',
            'severity' => 'error',
            'blocks_scoring' => true,
            'parameters' => ['min' => 0, 'max' => 2000000],
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 300000,
        ]);

        $log = IngestionLog::query()->create([
            'source' => 'test',
            'command' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $service = app(DataValidationService::class);
        $report = $service->validateIngestion($log, 'test', 2024);

        $this->assertFalse($report->hasBlockingFailures());
        $this->assertEquals(1, $report->passedCount());
    }

    public function test_range_validation_fails_out_of_range_data(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Test Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.1,
            'is_active' => true,
        ]);

        ValidationRule::query()->create([
            'indicator_id' => $indicator->id,
            'source' => 'test',
            'rule_type' => 'range',
            'name' => 'Income must be positive',
            'severity' => 'error',
            'blocks_scoring' => true,
            'parameters' => ['min' => 0, 'max' => 2000000],
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => -500,
        ]);

        $log = IngestionLog::query()->create([
            'source' => 'test',
            'command' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $service = app(DataValidationService::class);
        $report = $service->validateIngestion($log, 'test', 2024);

        $this->assertTrue($report->hasBlockingFailures());
        $this->assertEquals(1, $report->failedCount());

        // Results are stored in database
        $this->assertDatabaseHas('validation_results', [
            'ingestion_log_id' => $log->id,
            'status' => 'failed',
        ]);
    }

    public function test_completeness_validation_fails_when_coverage_too_low(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Test Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.1,
            'is_active' => true,
        ]);

        ValidationRule::query()->create([
            'indicator_id' => $indicator->id,
            'source' => 'test',
            'rule_type' => 'completeness',
            'name' => 'Coverage check',
            'severity' => 'warning',
            'parameters' => ['min_coverage_pct' => 80],
        ]);

        // Create 100 DeSO areas but only 10 with data
        for ($i = 0; $i < 100; $i++) {
            \Illuminate\Support\Facades\DB::table('deso_areas')->insert([
                'deso_code' => sprintf('TEST%04d', $i),
                'kommun_code' => '0180',
                'lan_code' => '01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        for ($i = 0; $i < 10; $i++) {
            IndicatorValue::query()->create([
                'deso_code' => sprintf('TEST%04d', $i),
                'indicator_id' => $indicator->id,
                'year' => 2024,
                'raw_value' => 300000 + $i,
            ]);
        }

        $log = IngestionLog::query()->create([
            'source' => 'test',
            'command' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $service = app(DataValidationService::class);
        $report = $service->validateIngestion($log, 'test', 2024);

        $this->assertTrue($report->hasWarnings());
    }

    public function test_validation_report_summary(): void
    {
        $report = new \App\DataTransferObjects\ValidationReport([
            new \App\DataTransferObjects\ValidationRuleResult(
                ruleName: 'Test Rule 1',
                status: 'passed',
                severity: 'error',
                blocksScoring: true,
            ),
            new \App\DataTransferObjects\ValidationRuleResult(
                ruleName: 'Test Rule 2',
                status: 'failed',
                severity: 'warning',
                blocksScoring: false,
                message: 'Low coverage',
            ),
        ]);

        $this->assertFalse($report->hasBlockingFailures());
        $this->assertTrue($report->hasWarnings());
        $this->assertEquals(1, $report->passedCount());
        $this->assertEquals(1, $report->failedCount());
        $this->assertStringContainsString('Low coverage', $report->summary());
    }

    // === Sentinel Areas ===

    public function test_sentinel_area_seeder_creates_areas(): void
    {
        $this->seed(\Database\Seeders\SentinelAreaSeeder::class);

        $this->assertGreaterThanOrEqual(9, SentinelArea::count());
        $this->assertDatabaseHas('sentinel_areas', ['expected_tier' => 'top']);
        $this->assertDatabaseHas('sentinel_areas', ['expected_tier' => 'bottom']);
        $this->assertDatabaseHas('sentinel_areas', ['expected_tier' => 'middle']);
    }

    public function test_sentinel_check_passes_for_in_range_scores(): void
    {
        SentinelArea::query()->create([
            'deso_code' => 'TEST0001',
            'name' => 'Test Affluent',
            'expected_tier' => 'top',
            'expected_score_min' => 70,
            'expected_score_max' => 95,
        ]);

        $version = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'pending',
            'computed_at' => now(),
        ]);

        CompositeScore::query()->create([
            'deso_code' => 'TEST0001',
            'year' => 2024,
            'score' => 82.5,
            'score_version_id' => $version->id,
            'computed_at' => now(),
        ]);

        $this->artisan('check:sentinels', ['--year' => 2024])
            ->assertExitCode(0);
    }

    public function test_sentinel_check_fails_for_out_of_range_scores(): void
    {
        SentinelArea::query()->create([
            'deso_code' => 'TEST0001',
            'name' => 'Test Affluent',
            'expected_tier' => 'top',
            'expected_score_min' => 70,
            'expected_score_max' => 95,
        ]);

        $version = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'pending',
            'computed_at' => now(),
        ]);

        CompositeScore::query()->create([
            'deso_code' => 'TEST0001',
            'year' => 2024,
            'score' => 30.0,
            'score_version_id' => $version->id,
            'computed_at' => now(),
        ]);

        $this->artisan('check:sentinels', ['--year' => 2024])
            ->assertExitCode(1);
    }

    // === Score Versioning ===

    public function test_compute_scores_creates_version(): void
    {
        $indicator = Indicator::query()->create([
            'slug' => 'test_income',
            'name' => 'Test Income',
            'source' => 'test',
            'direction' => 'positive',
            'weight' => 0.5,
            'is_active' => true,
        ]);

        IndicatorValue::query()->create([
            'deso_code' => '0180C0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 300000,
            'normalized_value' => 0.75,
        ]);

        $service = new ScoringService;
        $service->computeScores(2024);

        $this->assertDatabaseHas('score_versions', [
            'year' => 2024,
            'status' => 'pending',
        ]);

        $version = ScoreVersion::query()->first();
        $this->assertNotNull($version);
        $this->assertEquals(1, $version->deso_count);
        $this->assertNotNull($version->mean_score);

        // Scores are linked to the version
        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();
        $this->assertEquals($version->id, $score->score_version_id);
    }

    public function test_publish_command_changes_status(): void
    {
        $version = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'validated',
            'deso_count' => 100,
            'computed_at' => now(),
        ]);

        $this->artisan('scores:publish', ['--score-version' => $version->id])
            ->assertExitCode(0);

        $version->refresh();
        $this->assertEquals('published', $version->status);
        $this->assertNotNull($version->published_at);
    }

    public function test_publish_supersedes_previous_published_version(): void
    {
        $old = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'published',
            'deso_count' => 100,
            'computed_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        $new = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'validated',
            'deso_count' => 100,
            'computed_at' => now(),
        ]);

        $this->artisan('scores:publish', ['--score-version' => $new->id])
            ->assertExitCode(0);

        $old->refresh();
        $new->refresh();
        $this->assertEquals('superseded', $old->status);
        $this->assertEquals('published', $new->status);
    }

    public function test_rollback_command_restores_previous_version(): void
    {
        $old = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'superseded',
            'deso_count' => 100,
            'computed_at' => now()->subDay(),
        ]);

        $current = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'published',
            'deso_count' => 100,
            'computed_at' => now(),
            'published_at' => now(),
        ]);

        $this->artisan('scores:rollback', [
            '--to-version' => $old->id,
            '--reason' => 'Bad data',
        ])->assertExitCode(0);

        $old->refresh();
        $current->refresh();
        $this->assertEquals('published', $old->status);
        $this->assertEquals('rolled_back', $current->status);
    }

    public function test_api_serves_published_version_scores(): void
    {
        $published = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'published',
            'deso_count' => 1,
            'computed_at' => now(),
            'published_at' => now(),
        ]);

        CompositeScore::query()->create([
            'deso_code' => 'TEST0001',
            'year' => 2024,
            'score' => 75.0,
            'score_version_id' => $published->id,
            'computed_at' => now(),
        ]);

        // Create a pending version with different score
        $pending = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'pending',
            'deso_count' => 1,
            'computed_at' => now(),
        ]);

        CompositeScore::query()->create([
            'deso_code' => 'TEST0001',
            'year' => 2024,
            'score' => 50.0,
            'score_version_id' => $pending->id,
            'computed_at' => now(),
        ]);

        $response = $this->getJson('/api/deso/scores?year=2024');
        $response->assertOk();

        $data = $response->json();
        $this->assertEquals(75.0, (float) $data['TEST0001']['score']);
    }

    // === Anomaly Detection ===

    public function test_drift_detector_finds_large_drifts(): void
    {
        $old = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'published',
            'deso_count' => 2,
            'mean_score' => 50,
            'stddev_score' => 10,
            'computed_at' => now()->subDay(),
            'published_at' => now()->subDay(),
        ]);

        $new = ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'pending',
            'deso_count' => 2,
            'mean_score' => 55,
            'stddev_score' => 10,
            'computed_at' => now(),
        ]);

        // Old scores
        CompositeScore::query()->create([
            'deso_code' => 'TEST0001', 'year' => 2024, 'score' => 80.0,
            'score_version_id' => $old->id, 'computed_at' => now(),
        ]);
        CompositeScore::query()->create([
            'deso_code' => 'TEST0002', 'year' => 2024, 'score' => 20.0,
            'score_version_id' => $old->id, 'computed_at' => now(),
        ]);

        // New scores with drift
        CompositeScore::query()->create([
            'deso_code' => 'TEST0001', 'year' => 2024, 'score' => 50.0,
            'score_version_id' => $new->id, 'computed_at' => now(),
        ]);
        CompositeScore::query()->create([
            'deso_code' => 'TEST0002', 'year' => 2024, 'score' => 60.0,
            'score_version_id' => $new->id, 'computed_at' => now(),
        ]);

        $detector = new ScoreDriftDetector;
        $report = $detector->detect($new, $old);

        $this->assertEquals(2, $report->totalAreas);
        $this->assertGreaterThan(20, $report->maxDrift);
        $this->assertCount(2, $report->areasWithLargeDrift);
    }

    public function test_drift_report_detects_systemic_shift(): void
    {
        $report = new DriftReport(
            totalAreas: 100,
            meanDrift: 10,
            maxDrift: 30,
            meanScoreNew: 60,
            meanScoreOld: 50,
            stddevNew: 10,
            stddevOld: 10,
        );

        $this->assertTrue($report->hasSystemicShift());
        $this->assertFalse($report->hasStddevShift());
    }

    // === Data Freshness ===

    public function test_freshness_check_command_runs(): void
    {
        Indicator::query()->create([
            'slug' => 'test_fresh',
            'name' => 'Test Fresh',
            'source' => 'scb',
            'direction' => 'positive',
            'weight' => 0.1,
            'is_active' => true,
            'last_ingested_at' => now(),
        ]);

        $this->artisan('check:freshness')
            ->assertExitCode(0);

        $indicator = Indicator::query()->where('slug', 'test_fresh')->first();
        $this->assertEquals('current', $indicator->freshness_status);
    }

    public function test_freshness_marks_stale_indicators(): void
    {
        Indicator::query()->create([
            'slug' => 'test_stale',
            'name' => 'Test Stale',
            'source' => 'scb',
            'direction' => 'positive',
            'weight' => 0.1,
            'is_active' => true,
            'last_ingested_at' => now()->subMonths(16),
        ]);

        $this->artisan('check:freshness')
            ->assertExitCode(0);

        $indicator = Indicator::query()->where('slug', 'test_stale')->first();
        $this->assertEquals('stale', $indicator->freshness_status);
    }

    // === Data Quality Dashboard ===

    public function test_data_quality_dashboard_loads(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['is_admin' => true]);
        $response = $this->actingAs($admin)->get('/admin/data-quality');
        $response->assertOk();
    }

    // === Score Version Model ===

    public function test_score_version_published_scope(): void
    {
        ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'pending',
            'computed_at' => now(),
        ]);

        ScoreVersion::query()->create([
            'year' => 2024,
            'status' => 'published',
            'computed_at' => now(),
            'published_at' => now(),
        ]);

        $this->assertEquals(1, ScoreVersion::published()->count());
    }
}
