<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\School;
use App\Models\SchoolStatistic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AggregateSchoolIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    private function getOrCreateMeritIndicator(): Indicator
    {
        return Indicator::query()->firstOrCreate(
            ['slug' => 'school_merit_value_avg'],
            [
                'name' => 'Average Merit Value',
                'source' => 'skolverket',
                'unit' => 'points',
                'direction' => 'positive',
                'weight' => 0.12,
                'is_active' => true,
            ]
        );
    }

    public function test_aggregates_merit_value_to_deso(): void
    {
        $indicator = $this->getOrCreateMeritIndicator();

        $school1 = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Grundskolan',
        ]);
        $school2 = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Grundskolan',
        ]);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school1->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 200.0,
            'student_count' => 100,
        ]);
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school2->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 300.0,
            'student_count' => 100,
        ]);

        $this->artisan('aggregate:school-indicators', ['--academic-year' => '2023/24'])
            ->assertSuccessful();

        $this->assertDatabaseHas('indicator_values', [
            'deso_code' => '0114A0010',
            'indicator_id' => $indicator->id,
            'year' => 2024,
        ]);

        $value = DB::table('indicator_values')
            ->where('deso_code', '0114A0010')
            ->where('indicator_id', $indicator->id)
            ->value('raw_value');

        // Equal student count: (200*100 + 300*100) / (100+100) = 250
        $this->assertEquals(250.0, (float) $value);
    }

    public function test_aggregation_weights_by_student_count(): void
    {
        $this->getOrCreateMeritIndicator();

        $school1 = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Grundskolan',
        ]);
        $school2 = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Grundskolan',
        ]);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school1->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 200.0,
            'student_count' => 300,
        ]);
        SchoolStatistic::factory()->create([
            'school_unit_code' => $school2->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 300.0,
            'student_count' => 100,
        ]);

        $this->artisan('aggregate:school-indicators', ['--academic-year' => '2023/24'])
            ->assertSuccessful();

        $value = DB::table('indicator_values')
            ->where('deso_code', '0114A0010')
            ->value('raw_value');

        // Weighted: (200*300 + 300*100) / (300+100) = 90000/400 = 225
        $this->assertEquals(225.0, (float) $value);
    }

    public function test_aggregation_skips_non_grundskola_schools(): void
    {
        $indicator = $this->getOrCreateMeritIndicator();

        $grundskola = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Grundskolan',
            'school_forms' => ['Grundskola'],
        ]);
        $gym = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Gymnasieskolan',
            'school_forms' => ['Gymnasieskola'],
        ]);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $grundskola->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 250.0,
        ]);
        SchoolStatistic::factory()->create([
            'school_unit_code' => $gym->school_unit_code,
            'academic_year' => '2023/24',
            'merit_value_17' => 180.0,
        ]);

        $this->artisan('aggregate:school-indicators', ['--academic-year' => '2023/24'])
            ->assertSuccessful();

        $value = DB::table('indicator_values')
            ->where('deso_code', '0114A0010')
            ->where('indicator_id', $indicator->id)
            ->value('raw_value');

        $this->assertEquals(250.0, (float) $value);
    }

    public function test_aggregation_handles_deso_with_no_schools(): void
    {
        $this->getOrCreateMeritIndicator();

        $this->artisan('aggregate:school-indicators', ['--academic-year' => '2023/24'])
            ->assertSuccessful();

        $this->assertDatabaseCount('indicator_values', 0);
    }

    public function test_academic_year_to_calendar_year_mapping(): void
    {
        $indicator = $this->getOrCreateMeritIndicator();

        $school = School::factory()->create([
            'deso_code' => '0114A0010',
            'status' => 'active',
            'type_of_schooling' => 'Grundskolan',
        ]);

        SchoolStatistic::factory()->create([
            'school_unit_code' => $school->school_unit_code,
            'academic_year' => '2024/25',
            'merit_value_17' => 240.0,
        ]);

        $this->artisan('aggregate:school-indicators', ['--academic-year' => '2024/25'])
            ->assertSuccessful();

        $this->assertDatabaseHas('indicator_values', [
            'deso_code' => '0114A0010',
            'indicator_id' => $indicator->id,
            'year' => 2025,
        ]);
    }
}
