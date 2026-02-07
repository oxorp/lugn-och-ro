<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolStatistic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_school_can_be_created_with_factory(): void
    {
        $school = School::factory()->create();

        $this->assertDatabaseHas('schools', [
            'school_unit_code' => $school->school_unit_code,
        ]);
    }

    public function test_school_has_statistics_relationship(): void
    {
        $school = School::factory()->create();
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2023/24',
        ]);

        $this->assertCount(1, $school->statistics);
    }

    public function test_school_has_latest_statistics_relationship(): void
    {
        $school = School::factory()->create();
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2022/23',
            'merit_value_17' => 200.0,
        ]);
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 250.0,
        ]);

        $latest = $school->latestStatistics;

        $this->assertNotNull($latest);
        $this->assertEquals('2023/24', $latest->academic_year);
        $this->assertEquals(250.0, (float) $latest->merit_value_17);
    }

    public function test_school_statistic_belongs_to_school(): void
    {
        $school = School::factory()->create();
        $stat = SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
        ]);

        $this->assertEquals($school->id, $stat->school->id);
    }

    public function test_schools_api_returns_schools_for_deso(): void
    {
        $school = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
        ]);
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 245.0,
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/deso/0114A0010/schools');

        $response->assertOk();
        $response->assertJsonPath('school_count', 1);
        $response->assertJsonCount(1, 'schools');
        $response->assertJsonPath('schools.0.school_unit_code', $school->school_unit_code);
        $response->assertJsonPath('schools.0.name', $school->name);
        $response->assertJsonPath('schools.0.school_forms', ['Grundskola']);
        $data = $response->json();
        $this->assertEquals(245.0, $data['schools'][0]['merit_value']);
    }

    public function test_schools_api_excludes_inactive_schools(): void
    {
        School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'inactive',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/deso/0114A0010/schools');

        $response->assertOk();
        $response->assertJsonPath('school_count', 0);
    }

    public function test_schools_api_returns_empty_for_deso_without_schools(): void
    {
        $response = $this->actingAsAdmin()->getJson('/api/deso/9999Z9999/schools');

        $response->assertOk();
        $response->assertJsonPath('school_count', 0);
        $response->assertJsonPath('schools', []);
    }

    public function test_schools_api_only_returns_schools_for_requested_deso(): void
    {
        School::factory()->create(['deso_code' => '0114A0010', 'status' => 'active']);
        School::factory()->create(['deso_code' => '0180A0020', 'status' => 'active']);

        $response = $this->actingAsAdmin()->getJson('/api/deso/0114A0010/schools');

        $response->assertOk();
        $response->assertJsonCount(1, 'schools');
    }

    public function test_schools_api_includes_null_stats_when_no_statistics(): void
    {
        School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/deso/0114A0010/schools');

        $response->assertOk();
        $response->assertJsonCount(1, 'schools');
        $response->assertJsonPath('schools.0.merit_value', null);
        $response->assertJsonPath('schools.0.goal_achievement', null);
    }

    public function test_school_statistic_unique_constraint(): void
    {
        $school = School::factory()->create();
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2023/24',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2023/24',
        ]);
    }

    public function test_school_unit_code_is_unique(): void
    {
        School::factory()->create(['school_unit_code' => '12345678']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        School::factory()->create(['school_unit_code' => '12345678']);
    }

    public function test_school_forms_is_json_cast(): void
    {
        $school = School::factory()->create([
            'school_forms' => ['Grundskola', 'Förskoleklass'],
        ]);

        $school->refresh();

        $this->assertIsArray($school->school_forms);
        $this->assertContains('Grundskola', $school->school_forms);
        $this->assertContains('Förskoleklass', $school->school_forms);
    }

    public function test_schools_api_returns_school_forms(): void
    {
        School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'school_forms' => ['Gymnasieskola'],
            'type_of_schooling' => 'Gymnasieskola',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/deso/0114A0010/schools');

        $response->assertOk();
        $response->assertJsonPath('schools.0.school_forms', ['Gymnasieskola']);
    }

    public function test_schools_api_returns_all_school_forms(): void
    {
        School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'school_forms' => ['Grundskola'],
            'type_of_schooling' => 'Grundskola',
        ]);
        School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'school_forms' => ['Gymnasieskola'],
            'type_of_schooling' => 'Gymnasieskola',
        ]);

        $response = $this->actingAsAdmin()->getJson('/api/deso/0114A0010/schools');

        $response->assertOk();
        $response->assertJsonCount(2, 'schools');
    }
}
