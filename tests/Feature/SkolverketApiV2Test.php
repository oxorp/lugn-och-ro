<?php

namespace Tests\Feature;

use App\Services\SkolverketApiService;
use Tests\TestCase;

class SkolverketApiV2Test extends TestCase
{
    public function test_parse_school_details_extracts_all_fields(): void
    {
        $service = new SkolverketApiService;

        $data = [
            'data' => [
                'type' => 'schoolunit',
                'schoolUnitCode' => '43038662',
                'attributes' => [
                    'displayName' => 'Färentuna skola',
                    'status' => 'AKTIV',
                    'municipalityCode' => '0125',
                    'schoolTypes' => ['GR', 'FKLASS'],
                    'addresses' => [
                        [
                            'type' => 'BESOKSADRESS',
                            'streetAddress' => 'Ölstavägen 5',
                            'postalCode' => '17998',
                            'locality' => 'Färentuna',
                            'geoCoordinates' => [
                                'latitude' => '59.393258',
                                'longitude' => '17.662399',
                            ],
                        ],
                    ],
                ],
            ],
            'included' => [
                'type' => 'organizer',
                'attributes' => [
                    'displayName' => 'EKERÖ KOMMUN',
                    'organizerType' => 'KOMMUN',
                ],
            ],
        ];

        $result = $service->parseSchoolDetails($data);

        $this->assertNotNull($result);
        $this->assertEquals('Färentuna skola', $result['name']);
        $this->assertEquals('active', $result['status']);
        $this->assertEquals('0125', $result['municipality_code']);
        $this->assertEquals(['GR', 'FKLASS'], $result['school_types']);
        $this->assertEquals(['Grundskola', 'Förskoleklass'], $result['school_forms']);
        $this->assertEquals('Grundskola, Förskoleklass', $result['type_of_schooling']);
        $this->assertEquals('EKERÖ KOMMUN', $result['operator_name']);
        $this->assertEquals('Kommunal', $result['operator_type']);
        $this->assertEqualsWithDelta(59.393258, $result['lat'], 0.001);
        $this->assertEqualsWithDelta(17.662399, $result['lng'], 0.001);
        $this->assertEquals('Ölstavägen 5', $result['address']);
        $this->assertEquals('17998', $result['postal_code']);
        $this->assertEquals('Färentuna', $result['city']);
    }

    public function test_parse_school_details_handles_missing_coordinates(): void
    {
        $service = new SkolverketApiService;

        $data = [
            'data' => [
                'attributes' => [
                    'displayName' => 'Testskolan',
                    'status' => 'AKTIV',
                    'municipalityCode' => '0180',
                    'schoolTypes' => ['GY'],
                    'addresses' => [
                        [
                            'type' => 'BESOKSADRESS',
                            'streetAddress' => 'Testgatan 1',
                            'postalCode' => '11111',
                            'locality' => 'Stockholm',
                        ],
                    ],
                ],
            ],
            'included' => [
                'attributes' => [
                    'displayName' => 'STOCKHOLMS STAD',
                    'organizerType' => 'KOMMUN',
                ],
            ],
        ];

        $result = $service->parseSchoolDetails($data);

        $this->assertNotNull($result);
        $this->assertNull($result['lat']);
        $this->assertNull($result['lng']);
        $this->assertEquals('Testgatan 1', $result['address']);
        $this->assertEquals(['Gymnasieskola'], $result['school_forms']);
    }

    public function test_parse_school_details_maps_status_correctly(): void
    {
        $service = new SkolverketApiService;

        $cases = [
            'AKTIV' => 'active',
            'VILANDE' => 'dormant',
            'UPPHORT' => 'ceased',
            'PLANERAD' => 'planned',
        ];

        foreach ($cases as $apiStatus => $expected) {
            $data = [
                'data' => [
                    'attributes' => [
                        'displayName' => 'Test',
                        'status' => $apiStatus,
                        'municipalityCode' => '0180',
                        'schoolTypes' => ['GR'],
                        'addresses' => [],
                    ],
                ],
                'included' => [
                    'attributes' => [
                        'displayName' => 'Test',
                        'organizerType' => 'KOMMUN',
                    ],
                ],
            ];

            $result = $service->parseSchoolDetails($data);
            $this->assertEquals($expected, $result['status'], "Status {$apiStatus} should map to {$expected}");
        }
    }

    public function test_parse_school_details_maps_organizer_types(): void
    {
        $service = new SkolverketApiService;

        $cases = [
            'KOMMUN' => 'Kommunal',
            'ENSKILD' => 'Fristående',
            'STAT' => 'Statlig',
            'REGION' => 'Region',
        ];

        foreach ($cases as $apiType => $expected) {
            $data = [
                'data' => [
                    'attributes' => [
                        'displayName' => 'Test',
                        'status' => 'AKTIV',
                        'municipalityCode' => '0180',
                        'schoolTypes' => ['GR'],
                        'addresses' => [],
                    ],
                ],
                'included' => [
                    'attributes' => [
                        'displayName' => 'Test',
                        'organizerType' => $apiType,
                    ],
                ],
            ];

            $result = $service->parseSchoolDetails($data);
            $this->assertEquals($expected, $result['operator_type'], "Type {$apiType} should map to {$expected}");
        }
    }

    public function test_school_form_names_constant_covers_all_codes(): void
    {
        $expectedCodes = ['FKLASS', 'FTH', 'OPPFTH', 'GR', 'GRAN', 'SP', 'SAM', 'GY', 'GYAN', 'VUX'];

        foreach ($expectedCodes as $code) {
            $this->assertArrayHasKey($code, SkolverketApiService::SCHOOL_FORM_NAMES, "Missing mapping for {$code}");
        }
    }
}
