<?php

namespace Tests\Unit;

use App\Services\VerdictService;
use PHPUnit\Framework\TestCase;

class VerdictServiceTest extends TestCase
{
    private VerdictService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VerdictService;
    }

    public function test_compute_verdict_grade_a_for_high_percentile(): void
    {
        $indicators = [
            $this->makeIndicator('median_income', 'economy', 85),
            $this->makeIndicator('employment_rate', 'economy', 82),
        ];

        $verdict = $this->service->computeVerdict('economy', $indicators);

        $this->assertEquals('A', $verdict['grade']);
        $this->assertEquals('#1a7a2e', $verdict['color']);
        $this->assertGreaterThanOrEqual(80, $verdict['score']);
    }

    public function test_compute_verdict_grade_e_for_low_percentile(): void
    {
        $indicators = [
            $this->makeIndicator('crime_violent_rate', 'safety', 10),
            $this->makeIndicator('perceived_safety', 'safety', 15),
        ];

        $verdict = $this->service->computeVerdict('safety', $indicators);

        $this->assertEquals('E', $verdict['grade']);
        $this->assertEquals('#c0392b', $verdict['color']);
    }

    public function test_compute_verdict_returns_empty_for_unknown_category(): void
    {
        $verdict = $this->service->computeVerdict('nonexistent', []);

        $this->assertNull($verdict['score']);
        $this->assertEquals("\u{2014}", $verdict['grade']);
    }

    public function test_compute_verdict_handles_no_matching_indicators(): void
    {
        $indicators = [
            $this->makeIndicator('some_other_slug', 'other', 50),
        ];

        $verdict = $this->service->computeVerdict('safety', $indicators);

        $this->assertNull($verdict['score']);
        $this->assertEquals('Inga data tillgängliga för denna kategori.', $verdict['verdict_sv']);
    }

    public function test_compute_all_verdicts_returns_four_categories(): void
    {
        $indicators = [
            $this->makeIndicator('median_income', 'economy', 70),
            $this->makeIndicator('crime_violent_rate', 'safety', 45),
            $this->makeIndicator('education_post_secondary_pct', 'education', 60),
            $this->makeIndicator('grocery_density', 'environment', 55),
        ];

        $verdicts = $this->service->computeAllVerdicts($indicators);

        $this->assertArrayHasKey('safety', $verdicts);
        $this->assertArrayHasKey('economy', $verdicts);
        $this->assertArrayHasKey('education', $verdicts);
        $this->assertArrayHasKey('environment', $verdicts);
    }

    public function test_trend_direction_improving(): void
    {
        $indicators = [
            $this->makeIndicator('median_income', 'economy', 70, 5),
            $this->makeIndicator('employment_rate', 'economy', 65, 3),
        ];

        $verdict = $this->service->computeVerdict('economy', $indicators);

        $this->assertEquals('improving', $verdict['trend_direction']);
    }

    public function test_trend_direction_declining(): void
    {
        $indicators = [
            $this->makeIndicator('median_income', 'economy', 40, -5),
            $this->makeIndicator('employment_rate', 'economy', 35, -3),
        ];

        $verdict = $this->service->computeVerdict('economy', $indicators);

        $this->assertEquals('declining', $verdict['trend_direction']);
    }

    public function test_trend_direction_stable(): void
    {
        $indicators = [
            $this->makeIndicator('median_income', 'economy', 50, 0),
            $this->makeIndicator('employment_rate', 'economy', 55, 1),
        ];

        $verdict = $this->service->computeVerdict('economy', $indicators);

        $this->assertEquals('stable', $verdict['trend_direction']);
    }

    public function test_verdict_text_contains_swedish(): void
    {
        $indicators = [
            $this->makeIndicator('median_income', 'economy', 70, 0, 287000, 'SEK'),
        ];

        $verdict = $this->service->computeVerdict('economy', $indicators);

        $this->assertStringContainsString('ekonomiska situationen', $verdict['verdict_sv']);
        $this->assertStringContainsString('287', $verdict['verdict_sv']);
    }

    public function test_grade_boundaries(): void
    {
        // Grade C: 40-59
        $indicators = [$this->makeIndicator('median_income', 'economy', 50)];
        $verdict = $this->service->computeVerdict('economy', $indicators);
        $this->assertEquals('C', $verdict['grade']);

        // Grade D: 20-39
        $indicators = [$this->makeIndicator('median_income', 'economy', 30)];
        $verdict = $this->service->computeVerdict('economy', $indicators);
        $this->assertEquals('D', $verdict['grade']);

        // Grade B: 60-79
        $indicators = [$this->makeIndicator('median_income', 'economy', 65)];
        $verdict = $this->service->computeVerdict('economy', $indicators);
        $this->assertEquals('B', $verdict['grade']);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeIndicator(
        string $slug,
        string $category,
        int $percentile,
        ?int $change1y = 0,
        float $rawValue = 100.0,
        string $unit = 'percent'
    ): array {
        return [
            'slug' => $slug,
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'category' => $category,
            'source' => 'test',
            'unit' => $unit,
            'direction' => 'positive',
            'raw_value' => $rawValue,
            'formatted_value' => (string) $rawValue,
            'normalized_value' => $percentile / 100,
            'percentile' => $percentile,
            'description' => null,
            'trend' => [
                'years' => [2023, 2024],
                'percentiles' => [$percentile - ($change1y ?? 0), $percentile],
                'raw_values' => [$rawValue, $rawValue],
                'change_1y' => $change1y,
                'change_3y' => null,
                'change_5y' => null,
            ],
        ];
    }
}
