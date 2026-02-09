<?php

namespace Tests\Feature;

use App\Services\SkolverketApiService;
use Tests\TestCase;

class HistoricalIngestionTest extends TestCase
{
    public function test_parse_all_years_returns_multiple_years(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'averageGradesMeritRating9thGrade' => [
                    ['timePeriod' => '2020/21', 'value' => '241,3', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2021/22', 'value' => '238,7', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2022/23', 'value' => '240,1', 'valueType' => 'EXISTS'],
                ],
                'ratioOfPupilsIn9thGradeWithAllSubjectsPassed' => [
                    ['timePeriod' => '2020/21', 'value' => '72,5', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2021/22', 'value' => '..', 'valueType' => 'MISSING'],
                    ['timePeriod' => '2022/23', 'value' => '75,0', 'valueType' => 'EXISTS'],
                ],
                'certifiedTeachersQuota' => [
                    ['timePeriod' => '2020/21', 'value' => '85,2', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2021/22', 'value' => '87,1', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2022/23', 'value' => '88,0', 'valueType' => 'EXISTS'],
                ],
                'ratioOfPupils9thGradeEligibleForNationalProgramYR' => [
                    ['timePeriod' => '2020/21', 'value' => '82,0', 'valueType' => 'EXISTS'],
                ],
                'totalNumberOfPupils' => [
                    ['timePeriod' => '2022/23', 'value' => 'cirka 340', 'valueType' => 'EXISTS'],
                ],
            ],
        ];

        $result = $service->parseAllYearsGrundskolaStats($responseData);

        $this->assertCount(3, $result);
        $this->assertArrayHasKey('2020/21', $result);
        $this->assertArrayHasKey('2021/22', $result);
        $this->assertArrayHasKey('2022/23', $result);

        // 2020/21: merit, goal, teacher, eligibility
        $this->assertEqualsWithDelta(241.3, $result['2020/21']['merit_value_17'], 0.01);
        $this->assertEqualsWithDelta(72.5, $result['2020/21']['goal_achievement_pct'], 0.01);
        $this->assertEqualsWithDelta(85.2, $result['2020/21']['teacher_certification_pct'], 0.01);
        $this->assertEqualsWithDelta(82.0, $result['2020/21']['eligibility_pct'], 0.01);
        $this->assertNull($result['2020/21']['student_count']);

        // 2021/22: merit + teacher only (goal was MISSING)
        $this->assertEqualsWithDelta(238.7, $result['2021/22']['merit_value_17'], 0.01);
        $this->assertNull($result['2021/22']['goal_achievement_pct']);
        $this->assertEqualsWithDelta(87.1, $result['2021/22']['teacher_certification_pct'], 0.01);

        // 2022/23: has student count
        $this->assertEquals(340, $result['2022/23']['student_count']);
    }

    public function test_parse_all_years_skips_non_exists_entries(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'averageGradesMeritRating9thGrade' => [
                    ['timePeriod' => '2020/21', 'value' => '..', 'valueType' => 'MISSING'],
                    ['timePeriod' => '2021/22', 'value' => '.', 'valueType' => 'MISSING'],
                ],
                'certifiedTeachersQuota' => [
                    ['timePeriod' => '2020/21', 'value' => '80,0', 'valueType' => 'EXISTS'],
                ],
            ],
        ];

        $result = $service->parseAllYearsGrundskolaStats($responseData);

        // Only 2020/21 has EXISTS data (teacher cert)
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('2020/21', $result);
        $this->assertNull($result['2020/21']['merit_value_17']);
        $this->assertEqualsWithDelta(80.0, $result['2020/21']['teacher_certification_pct'], 0.01);
    }

    public function test_parse_all_years_returns_empty_when_no_valid_data(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'averageGradesMeritRating9thGrade' => [
                    ['timePeriod' => '2020/21', 'value' => '..', 'valueType' => 'MISSING'],
                ],
            ],
        ];

        $result = $service->parseAllYearsGrundskolaStats($responseData);

        $this->assertEmpty($result);
    }

    public function test_parse_all_years_handles_swedish_decimals(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'certifiedTeachersQuota' => [
                    ['timePeriod' => '2023/24', 'value' => '92,5', 'valueType' => 'EXISTS'],
                ],
            ],
        ];

        $result = $service->parseAllYearsGrundskolaStats($responseData);

        $this->assertEqualsWithDelta(92.5, $result['2023/24']['teacher_certification_pct'], 0.01);
    }

    public function test_parse_all_years_extracts_student_count_from_text(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'certifiedTeachersQuota' => [
                    ['timePeriod' => '2023/24', 'value' => '90,0', 'valueType' => 'EXISTS'],
                ],
                'totalNumberOfPupils' => [
                    ['timePeriod' => '2023/24', 'value' => 'cirka 560', 'valueType' => 'EXISTS'],
                ],
            ],
        ];

        $result = $service->parseAllYearsGrundskolaStats($responseData);

        $this->assertEquals(560, $result['2023/24']['student_count']);
    }

    public function test_parse_all_years_fills_null_defaults(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'certifiedTeachersQuota' => [
                    ['timePeriod' => '2020/21', 'value' => '85,0', 'valueType' => 'EXISTS'],
                ],
            ],
        ];

        $result = $service->parseAllYearsGrundskolaStats($responseData);

        $this->assertArrayHasKey('merit_value_17', $result['2020/21']);
        $this->assertArrayHasKey('goal_achievement_pct', $result['2020/21']);
        $this->assertArrayHasKey('eligibility_pct', $result['2020/21']);
        $this->assertArrayHasKey('student_count', $result['2020/21']);
        $this->assertNull($result['2020/21']['merit_value_17']);
        $this->assertNull($result['2020/21']['goal_achievement_pct']);
        $this->assertNull($result['2020/21']['eligibility_pct']);
        $this->assertNull($result['2020/21']['student_count']);
    }

    public function test_original_parser_still_works(): void
    {
        $service = new SkolverketApiService;

        $responseData = [
            'body' => [
                'averageGradesMeritRating9thGrade' => [
                    ['timePeriod' => '2020/21', 'value' => '241,3', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2022/23', 'value' => '240,1', 'valueType' => 'EXISTS'],
                ],
                'certifiedTeachersQuota' => [
                    ['timePeriod' => '2020/21', 'value' => '85,2', 'valueType' => 'EXISTS'],
                    ['timePeriod' => '2022/23', 'value' => '88,0', 'valueType' => 'EXISTS'],
                ],
                'ratioOfPupilsIn9thGradeWithAllSubjectsPassed' => [],
                'ratioOfPupils9thGradeEligibleForNationalProgramYR' => [],
                'totalNumberOfPupils' => [],
            ],
        ];

        $result = $service->parseGrundskolaStatsResponse($responseData);

        // Should return the LATEST values
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(240.1, $result['merit_value_17'], 0.01);
        $this->assertEqualsWithDelta(88.0, $result['teacher_certification_pct'], 0.01);
        $this->assertEquals('2022/23', $result['academic_year']);
    }
}
