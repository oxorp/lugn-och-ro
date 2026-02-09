<?php

namespace Tests\Feature;

use App\Services\ScbApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScbApiServiceTest extends TestCase
{
    /**
     * Verify education transform uses dimension codes (not positional indices)
     * to correctly identify post-secondary and below-secondary education levels.
     *
     * This is a regression test for a bug where SCB returned education levels
     * in a different order than the query requested, causing values to be
     * assigned to the wrong indicator.
     */
    public function test_education_post_secondary_uses_keyed_dimension_values(): void
    {
        // SCB returns UtbildningsNiva in its own order: 21, 3+4, 5, 6, US
        // NOT in the query order: 5, 6, 21, 3+4, US
        $scbResponse = [
            'id' => ['Region', 'UtbildningsNiva', 'ContentsCode', 'Tid'],
            'size' => [2, 5, 1, 1],
            'value' => [
                // DeSO 1 (Lund-like): 21=32, 3+4=200, 5=86, 6=162, US=9
                32, 200, 86, 162, 9,
                // DeSO 2 (Filipstad-like): 21=80, 3+4=300, 5=50, 6=30, US=20
                80, 300, 50, 30, 20,
            ],
            'dimension' => [
                'Region' => [
                    'category' => [
                        'index' => ['1281A0010_DeSO2025' => 0, '1782A0010_DeSO2025' => 1],
                    ],
                ],
                'UtbildningsNiva' => [
                    'category' => [
                        'index' => ['21' => 0, '3+4' => 1, '5' => 2, '6' => 3, 'US' => 4],
                        'label' => [
                            '21' => 'primary and lower secondary',
                            '3+4' => 'upper secondary',
                            '5' => 'post-secondary <3yr',
                            '6' => 'post-secondary 3yr+',
                            'US' => 'unknown',
                        ],
                    ],
                ],
                'ContentsCode' => [
                    'category' => ['index' => ['000007Z6' => 0]],
                ],
                'Tid' => [
                    'category' => ['index' => ['2024' => 0]],
                ],
            ],
        ];

        Http::fake([
            'api.scb.se/*' => Http::response($scbResponse, 200),
        ]);

        $service = new ScbApiService;

        // Test post-secondary: should use codes '5' + '6'
        $postSecResult = $service->fetchIndicator('education_post_secondary_pct', 2024);

        // Lund DeSO: (86 + 162) / (32 + 200 + 86 + 162 + 9) = 248/489 = 50.72%
        $this->assertArrayHasKey('1281A0010', $postSecResult);
        $this->assertEqualsWithDelta(50.72, $postSecResult['1281A0010'], 0.1);

        // Filipstad DeSO: (50 + 30) / (80 + 300 + 50 + 30 + 20) = 80/480 = 16.67%
        $this->assertArrayHasKey('1782A0010', $postSecResult);
        $this->assertEqualsWithDelta(16.67, $postSecResult['1782A0010'], 0.1);

        // Test below-secondary: should use code '21'
        $belowSecResult = $service->fetchIndicator('education_below_secondary_pct', 2024);

        // Lund DeSO: 32 / 489 = 6.54%
        $this->assertEqualsWithDelta(6.54, $belowSecResult['1281A0010'], 0.1);

        // Filipstad DeSO: 80 / 480 = 16.67%
        $this->assertEqualsWithDelta(16.67, $belowSecResult['1782A0010'], 0.1);
    }

    /**
     * Verify that indicators without multi-value variable dimensions
     * still work correctly (no keying applied).
     */
    public function test_single_value_indicators_unaffected_by_keying(): void
    {
        $scbResponse = [
            'id' => ['Region', 'ContentsCode', 'Tid'],
            'size' => [2, 1, 1],
            'value' => [325.5, 198.2],
            'dimension' => [
                'Region' => [
                    'category' => [
                        'index' => ['1281A0010_DeSO2025' => 0, '1782A0010_DeSO2025' => 1],
                    ],
                ],
                'ContentsCode' => [
                    'category' => ['index' => ['000008AB' => 0]],
                ],
                'Tid' => [
                    'category' => ['index' => ['2024' => 0]],
                ],
            ],
        ];

        Http::fake([
            'api.scb.se/*' => Http::response($scbResponse, 200),
        ]);

        $service = new ScbApiService;
        $result = $service->fetchIndicator('median_income', 2024);

        // multiply_1000 transform: 325.5 * 1000 = 325500
        $this->assertArrayHasKey('1281A0010', $result);
        $this->assertEquals(325500, $result['1281A0010']);
        $this->assertEquals(198200, $result['1782A0010']);
    }

    /**
     * Verify ratio_first_over_last works with keyed arrays
     * (foreign_background_pct uses UtlBakgrund dimension).
     */
    public function test_foreign_background_ratio_with_keyed_values(): void
    {
        $scbResponse = [
            'id' => ['Region', 'UtlBakgrund', 'Kon', 'ContentsCode', 'Tid'],
            'size' => [1, 2, 1, 1, 1],
            'value' => [133, 841],
            'dimension' => [
                'Region' => [
                    'category' => [
                        'index' => ['1281A0010_DeSO2025' => 0],
                    ],
                ],
                'UtlBakgrund' => [
                    'category' => [
                        'index' => ['1' => 0, 'SA' => 1],
                    ],
                ],
                'Kon' => [
                    'category' => ['index' => ['1+2' => 0]],
                ],
                'ContentsCode' => [
                    'category' => ['index' => ['000007Y4' => 0]],
                ],
                'Tid' => [
                    'category' => ['index' => ['2024' => 0]],
                ],
            ],
        ];

        Http::fake([
            'api.scb.se/*' => Http::response($scbResponse, 200),
        ]);

        $service = new ScbApiService;
        $result = $service->fetchIndicator('foreign_background_pct', 2024);

        // ratio_first_over_last: 133 / 841 * 100 = 15.82%
        $this->assertArrayHasKey('1281A0010', $result);
        $this->assertEqualsWithDelta(15.82, $result['1281A0010'], 0.1);
    }
}
