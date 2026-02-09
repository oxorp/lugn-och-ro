<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_data_completeness_page_loads(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.data-completeness'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/data-completeness')
            ->has('matrix')
            ->has('years')
            ->has('summary')
        );
    }

    public function test_completeness_matrix_includes_all_active_indicators(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $activeCount = Indicator::where('is_active', true)->count();

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.data-completeness'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('matrix', $activeCount)
        );
    }

    public function test_completeness_shows_correct_coverage_for_indicator_with_data(): void
    {
        // Deactivate any existing indicators from migrations
        Indicator::query()->update(['is_active' => false]);

        $indicator = Indicator::create([
            'slug' => 'test_indicator',
            'name' => 'Test Indicator',
            'source' => 'test',
            'category' => 'test',
            'direction' => 'positive',
            'weight' => 0.1,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
            'display_order' => 1,
        ]);

        // Insert a DeSO and indicator values
        DB::table('deso_areas')->insert([
            'deso_code' => 'TEST0001',
            'deso_name' => 'Test Area 1',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
            'lan_code' => '01',
            'geom' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((18.0 59.0, 18.1 59.0, 18.1 59.1, 18.0 59.1, 18.0 59.0)))', 4326)"),
        ]);

        DB::table('deso_areas')->insert([
            'deso_code' => 'TEST0002',
            'deso_name' => 'Test Area 2',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
            'lan_code' => '01',
            'geom' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((18.1 59.0, 18.2 59.0, 18.2 59.1, 18.1 59.1, 18.1 59.0)))', 4326)"),
        ]);

        DB::table('indicator_values')->insert([
            'deso_code' => 'TEST0001',
            'indicator_id' => $indicator->id,
            'year' => 2024,
            'raw_value' => 100.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.data-completeness'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->has('matrix', 1);

            $matrix = $page->toArray()['props']['matrix'];
            $row = $matrix[0];

            $this->assertEquals('test_indicator', $row['indicator']['slug']);
            $this->assertTrue($row['years'][2024]['has_data']);
            $this->assertEquals(1, $row['years'][2024]['count']);
            $this->assertGreaterThan(0, $row['years'][2024]['coverage_pct']);
        });
    }

    public function test_completeness_shows_no_data_for_missing_years(): void
    {
        Indicator::create([
            'slug' => 'empty_indicator',
            'name' => 'Empty Indicator',
            'source' => 'test',
            'category' => 'test',
            'direction' => 'positive',
            'weight' => 0.1,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.data-completeness'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $matrix = $page->toArray()['props']['matrix'];
            $row = $matrix[0];

            // All years should show has_data = false
            foreach ($row['years'] as $yearData) {
                $this->assertFalse($yearData['has_data']);
                $this->assertEquals(0, $yearData['count']);
            }
        });
    }

    public function test_completeness_summary_counts_filled_cells(): void
    {
        Indicator::query()->update(['is_active' => false]);

        $indicator = Indicator::create([
            'slug' => 'summary_test',
            'name' => 'Summary Test',
            'source' => 'test',
            'category' => 'test',
            'direction' => 'positive',
            'weight' => 0.1,
            'normalization' => 'rank_percentile',
            'normalization_scope' => 'national',
            'is_active' => true,
            'display_order' => 1,
        ]);

        DB::table('deso_areas')->insert([
            'deso_code' => 'SUM0001',
            'deso_name' => 'Summary Area',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
            'lan_code' => '01',
            'geom' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((18.0 59.0, 18.1 59.0, 18.1 59.1, 18.0 59.1, 18.0 59.0)))', 4326)"),
        ]);

        // Insert data for 2 years
        foreach ([2023, 2024] as $year) {
            DB::table('indicator_values')->insert([
                'deso_code' => 'SUM0001',
                'indicator_id' => $indicator->id,
                'year' => $year,
                'raw_value' => 50.0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.data-completeness'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $summary = $page->toArray()['props']['summary'];
            $this->assertEquals(1, $summary['total_indicators']);
            $this->assertEquals(2, $summary['filled_cells']);
        });
    }

    public function test_non_admin_cannot_access_data_completeness(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)
            ->get(route('admin.data-completeness'));

        $response->assertForbidden();
    }

    public function test_guest_cannot_access_data_completeness(): void
    {
        $response = $this->get(route('admin.data-completeness'));

        $response->assertRedirect();
    }

    public function test_indicators_page_includes_years_with_data(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $indicator = Indicator::query()->where('slug', 'median_income')->first();

        DB::table('deso_areas')->insert([
            'deso_code' => 'YRS0001',
            'deso_name' => 'Years Test',
            'kommun_code' => '0180',
            'kommun_name' => 'Stockholm',
            'lan_code' => '01',
            'geom' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((18.0 59.0, 18.1 59.0, 18.1 59.1, 18.0 59.1, 18.0 59.0)))', 4326)"),
        ]);

        foreach ([2022, 2023, 2024] as $year) {
            DB::table('indicator_values')->insert([
                'deso_code' => 'YRS0001',
                'indicator_id' => $indicator->id,
                'year' => $year,
                'raw_value' => 300000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.indicators'));

        $response->assertOk();

        $indicators = $response->original->getData()['page']['props']['indicators'];
        $medianIncome = collect($indicators)->firstWhere('slug', 'median_income');

        $this->assertArrayHasKey('years_with_data', $medianIncome);
        $this->assertEquals([2022, 2023, 2024], $medianIncome['years_with_data']);
    }
}
