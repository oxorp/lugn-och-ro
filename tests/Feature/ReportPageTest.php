<?php

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_report_returns_200(): void
    {
        $report = Report::factory()->completed()->withSnapshot()->create();

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(200);
    }

    public function test_pending_report_returns_404(): void
    {
        $report = Report::factory()->create(['status' => 'pending']);

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(404);
    }

    public function test_report_view_count_increments(): void
    {
        $report = Report::factory()->completed()->withSnapshot()->create(['view_count' => 5]);

        $this->get("/reports/{$report->uuid}");

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'view_count' => 6,
        ]);
    }

    public function test_report_passes_snapshot_data_to_frontend(): void
    {
        $report = Report::factory()->completed()->withSnapshot()->create();

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertInertia(fn ($page) => $page
            ->component('reports/show')
            ->has('report.uuid')
            ->has('report.area_indicators')
            ->has('report.category_verdicts')
            ->has('report.schools')
            ->has('report.score_history')
            ->has('report.deso_meta')
            ->has('report.outlook')
            ->has('report.top_positive')
            ->has('report.top_negative')
            ->where('report.indicator_count', 19)
            ->where('report.year', 2024)
            ->where('report.model_version', 'v1.0')
        );
    }

    public function test_report_without_snapshot_returns_empty_arrays(): void
    {
        $report = Report::factory()->completed()->create();

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertInertia(fn ($page) => $page
            ->component('reports/show')
            ->where('report.area_indicators', [])
            ->where('report.schools', [])
            ->where('report.category_verdicts', [])
            ->where('report.indicator_count', 0)
        );
    }

    public function test_nonexistent_report_returns_404(): void
    {
        $response = $this->get('/reports/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    public function test_paid_report_also_accessible(): void
    {
        $report = Report::factory()->create([
            'status' => 'paid',
        ]);

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(200);
    }

    public function test_expired_report_returns_404(): void
    {
        $report = Report::factory()->expired()->create();

        $response = $this->get("/reports/{$report->uuid}");

        $response->assertStatus(404);
    }
}
