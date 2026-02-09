<?php

namespace Tests\Feature;

use App\Models\CompositeScore;
use App\Models\Indicator;
use App\Models\IndicatorValue;
use App\Models\ScorePenalty;
use App\Models\User;
use App\Models\VulnerabilityArea;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenaltySystemTest extends TestCase
{
    use RefreshDatabase;

    private ScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScoringService;
    }

    private function createTestIndicatorWithValue(string $desoCode, float $normalizedValue = 0.50): void
    {
        $indicator = Indicator::query()->firstOrCreate(
            ['slug' => 'test_indicator'],
            [
                'name' => 'Test Indicator',
                'source' => 'test',
                'direction' => 'positive',
                'weight' => 1.0,
                'is_active' => true,
            ]
        );

        IndicatorValue::query()->create([
            'deso_code' => $desoCode,
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 100,
            'normalized_value' => $normalizedValue,
        ]);
    }

    private function createVulnerabilityMapping(string $desoCode, string $tier, float $overlap = 0.50): void
    {
        $area = VulnerabilityArea::query()->create([
            'name' => 'Test Area '.$tier.'_'.uniqid(),
            'tier' => $tier,
            'police_region' => 'Stockholm',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        DB::statement('
            UPDATE vulnerability_areas SET geom = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(18.0, 59.3), 4326)::geography, 500)::geometry)
            WHERE id = ?
        ', [$area->id]);

        DB::table('deso_vulnerability_mapping')->insert([
            'deso_code' => $desoCode,
            'vulnerability_area_id' => $area->id,
            'overlap_fraction' => $overlap,
            'tier' => $tier,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Scoring with Penalties ──────────────────────────────────

    public function test_sarskilt_utsatt_penalty_applies_to_score(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        $this->createTestIndicatorWithValue('0180C0001', 0.50);
        $this->createVulnerabilityMapping('0180C0001', 'sarskilt_utsatt');

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();

        $this->assertNotNull($score);
        $this->assertEquals(50.0, (float) $score->raw_score_before_penalties);
        $this->assertEquals(35.0, (float) $score->score);
        $this->assertNotNull($score->penalties_applied);
        $this->assertCount(1, $score->penalties_applied);
        $this->assertEquals('vuln_sarskilt_utsatt', $score->penalties_applied[0]['slug']);
        $this->assertEquals(-15.0, $score->penalties_applied[0]['amount']);
    }

    public function test_utsatt_penalty_applies_to_score(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        $this->createTestIndicatorWithValue('0180C0001', 0.50);
        $this->createVulnerabilityMapping('0180C0001', 'utsatt');

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();

        $this->assertEquals(50.0, (float) $score->raw_score_before_penalties);
        $this->assertEquals(42.0, (float) $score->score);
        $this->assertEquals(-8.0, $score->penalties_applied[0]['amount']);
    }

    public function test_no_penalty_for_non_vulnerability_deso(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        $this->createTestIndicatorWithValue('0180C9999', 0.60);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C9999')->first();

        $this->assertNotNull($score);
        $this->assertEquals(60.0, (float) $score->score);
        $this->assertNull($score->raw_score_before_penalties);
        $this->assertNull($score->penalties_applied);
    }

    public function test_penalty_clamped_at_zero(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        $this->createTestIndicatorWithValue('0180C0001', 0.10);
        $this->createVulnerabilityMapping('0180C0001', 'sarskilt_utsatt');

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();

        $this->assertEquals(10.0, (float) $score->raw_score_before_penalties);
        $this->assertEquals(0.0, (float) $score->score);
    }

    public function test_only_worst_penalty_per_category_applied(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        $this->createTestIndicatorWithValue('0180C0001', 0.50);

        $this->createVulnerabilityMapping('0180C0001', 'sarskilt_utsatt', 0.30);
        $this->createVulnerabilityMapping('0180C0001', 'utsatt', 0.40);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();

        // Only worst penalty (-15) applies, not stacked (-15 + -8 = -23)
        $this->assertEquals(50.0, (float) $score->raw_score_before_penalties);
        $this->assertEquals(35.0, (float) $score->score);
        $this->assertCount(1, $score->penalties_applied);
        $this->assertEquals('vuln_sarskilt_utsatt', $score->penalties_applied[0]['slug']);
    }

    public function test_overlap_below_threshold_no_penalty(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        $this->createTestIndicatorWithValue('0180C0001', 0.50);
        $this->createVulnerabilityMapping('0180C0001', 'sarskilt_utsatt', 0.05);

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();

        $this->assertEquals(50.0, (float) $score->score);
        $this->assertNull($score->penalties_applied);
    }

    public function test_inactive_penalty_not_applied(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);
        ScorePenalty::query()->where('slug', 'vuln_sarskilt_utsatt')->update(['is_active' => false]);

        $this->createTestIndicatorWithValue('0180C0001', 0.50);
        $this->createVulnerabilityMapping('0180C0001', 'sarskilt_utsatt');

        $this->service->computeScores(2024);

        $score = CompositeScore::query()->where('deso_code', '0180C0001')->first();

        $this->assertEquals(50.0, (float) $score->score);
        $this->assertNull($score->penalties_applied);
    }

    // ── Admin Penalty Controller ──────────────────────────────────

    public function test_admin_penalties_page_loads(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $response = $this->actingAs($this->createAdmin())->get(route('admin.penalties'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/penalties')
            ->has('penalties', 2)
        );
    }

    public function test_admin_can_update_penalty_value(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $penalty = ScorePenalty::query()->where('slug', 'vuln_sarskilt_utsatt')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.penalties.update', $penalty), [
            'penalty_value' => -20.00,
            'penalty_type' => 'absolute',
            'is_active' => true,
            'color' => '#dc2626',
            'border_color' => '#991b1b',
            'opacity' => 0.20,
        ]);

        $response->assertRedirect();

        $penalty->refresh();
        $this->assertEquals(-20.0, (float) $penalty->penalty_value);
    }

    public function test_admin_can_toggle_penalty_active(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $penalty = ScorePenalty::query()->where('slug', 'vuln_utsatt')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.penalties.update', $penalty), [
            'penalty_value' => -8.00,
            'penalty_type' => 'absolute',
            'is_active' => false,
            'color' => '#f97316',
            'border_color' => '#c2410c',
            'opacity' => 0.15,
        ]);

        $response->assertRedirect();

        $penalty->refresh();
        $this->assertFalse($penalty->is_active);
    }

    public function test_penalty_value_must_be_negative_or_zero(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $penalty = ScorePenalty::query()->where('slug', 'vuln_sarskilt_utsatt')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.penalties.update', $penalty), [
            'penalty_value' => 5.00,
            'penalty_type' => 'absolute',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('penalty_value');
    }

    public function test_penalty_type_must_be_valid(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $penalty = ScorePenalty::query()->where('slug', 'vuln_sarskilt_utsatt')->first();

        $response = $this->actingAs($this->createAdmin())->put(route('admin.penalties.update', $penalty), [
            'penalty_value' => -15.00,
            'penalty_type' => 'invalid',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('penalty_type');
    }

    public function test_non_admin_cannot_access_penalties(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.penalties'));

        $response->assertForbidden();
    }

    // ── Vulnerability Area API ──────────────────────────────────

    public function test_vulnerability_areas_api_returns_json(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $area = VulnerabilityArea::query()->create([
            'name' => 'Rinkeby',
            'tier' => 'sarskilt_utsatt',
            'police_region' => 'Stockholm',
            'municipality_name' => 'Stockholm',
            'assessment_year' => 2025,
            'is_current' => true,
        ]);

        DB::statement('
            UPDATE vulnerability_areas SET geom = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(18.0, 59.3), 4326)::geography, 500)::geometry)
            WHERE id = ?
        ', [$area->id]);

        $response = $this->get('/api/vulnerability-areas');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Rinkeby',
            'tier' => 'sarskilt_utsatt',
            'tier_label' => 'Särskilt utsatt område',
        ]);
    }

    public function test_vulnerability_areas_excludes_non_current(): void
    {
        $this->seed(\Database\Seeders\ScorePenaltySeeder::class);

        $area = VulnerabilityArea::query()->create([
            'name' => 'Old Area',
            'tier' => 'utsatt',
            'police_region' => 'Väst',
            'assessment_year' => 2021,
            'is_current' => false,
        ]);

        DB::statement('
            UPDATE vulnerability_areas SET geom = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(12.0, 57.7), 4326)::geography, 500)::geometry)
            WHERE id = ?
        ', [$area->id]);

        $response = $this->get('/api/vulnerability-areas');

        $response->assertOk();
        $response->assertJsonCount(0);
    }
}
