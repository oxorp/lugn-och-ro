<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SkolverketApiService
{
    private const REGISTRY_BASE_URL = 'https://api.skolverket.se/skolenhetsregistret/v2';

    private const PLANNED_EDU_BASE_URL = 'https://api.skolverket.se/planned-educations/v3';

    private const PLANNED_EDU_ACCEPT = 'application/vnd.skolverket.plannededucations.api.v3.hal+json';

    /**
     * School form code to Swedish display name mapping.
     *
     * @var array<string, string>
     */
    public const SCHOOL_FORM_NAMES = [
        'FKLASS' => 'Förskoleklass',
        'FTH' => 'Fritidshem',
        'OPPFTH' => 'Öppen fritidsverksamhet',
        'GR' => 'Grundskola',
        'GRAN' => 'Anpassad grundskola',
        'SP' => 'Specialskola',
        'SAM' => 'Sameskola',
        'GY' => 'Gymnasieskola',
        'GYAN' => 'Anpassad gymnasieskola',
        'VUX' => 'Komvux',
    ];

    private int $delayMs;

    public function __construct(int $delayMs = 100)
    {
        $this->delayMs = $delayMs;
    }

    /**
     * Fetch ALL school units from Registry v2 list endpoint.
     * Returns minimal data: schoolUnitCode, name, status.
     *
     * @param  array<string>|null  $statuses  Filter by status (e.g., ['AKTIV', 'VILANDE'])
     * @return array<int, array{code: string, name: string, status: string}>
     */
    public function fetchAllSchoolUnits(?array $statuses = null): array
    {
        $url = self::REGISTRY_BASE_URL.'/school-units';

        if ($statuses) {
            $queryParts = array_map(fn (string $s) => 'status='.urlencode($s), $statuses);
            $url .= '?'.implode('&', $queryParts);
        }

        $response = Http::timeout(120)
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            Log::error('Failed to fetch school unit list from Registry v2', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return [];
        }

        $data = $response->json();
        $units = $data['data']['attributes'] ?? [];

        $schools = [];
        foreach ($units as $unit) {
            $schools[] = [
                'code' => $unit['schoolUnitCode'],
                'name' => $unit['name'],
                'status' => $unit['status'],
            ];
        }

        return $schools;
    }

    /**
     * Fetch a single school unit's full details from Registry v2.
     *
     * @return array{
     *     name: string,
     *     status: string,
     *     municipality_code: string|null,
     *     school_types: array<string>,
     *     type_of_schooling: string|null,
     *     school_forms: array<string>,
     *     operator_name: string|null,
     *     operator_type: string|null,
     *     lat: float|null,
     *     lng: float|null,
     *     address: string|null,
     *     postal_code: string|null,
     *     city: string|null,
     * }|null
     */
    public function fetchSchoolDetails(string $schoolUnitCode): ?array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->get(self::REGISTRY_BASE_URL.'/school-units/'.$schoolUnitCode);

        if (! $response->successful()) {
            return null;
        }

        return $this->parseSchoolDetails($response->json());
    }

    /**
     * Parse a v2 school unit detail response.
     *
     * @return array{
     *     name: string,
     *     status: string,
     *     municipality_code: string|null,
     *     school_types: array<string>,
     *     type_of_schooling: string|null,
     *     school_forms: array<string>,
     *     operator_name: string|null,
     *     operator_type: string|null,
     *     lat: float|null,
     *     lng: float|null,
     *     address: string|null,
     *     postal_code: string|null,
     *     city: string|null,
     * }|null
     */
    public function parseSchoolDetails(array $data): ?array
    {
        $attrs = $data['data']['attributes'] ?? [];
        $included = $data['included'] ?? [];

        if (empty($attrs)) {
            return null;
        }

        // Extract coordinates and address from BESOKSADRESS
        $lat = null;
        $lng = null;
        $address = null;
        $postalCode = null;
        $city = null;

        foreach ($attrs['addresses'] ?? [] as $addr) {
            if (($addr['type'] ?? '') === 'BESOKSADRESS') {
                $geo = $addr['geoCoordinates'] ?? [];
                $lat = isset($geo['latitude']) ? (float) $geo['latitude'] : null;
                $lng = isset($geo['longitude']) ? (float) $geo['longitude'] : null;
                $address = $addr['streetAddress'] ?? null;
                $postalCode = $addr['postalCode'] ?? null;
                $city = $addr['locality'] ?? null;

                break;
            }
        }

        // Map status to our internal values
        $status = match ($attrs['status'] ?? 'AKTIV') {
            'AKTIV' => 'active',
            'VILANDE' => 'dormant',
            'UPPHORT' => 'ceased',
            'PLANERAD' => 'planned',
            default => 'active',
        };

        // Map organizer type
        $organizerType = match ($included['attributes']['organizerType'] ?? null) {
            'KOMMUN' => 'Kommunal',
            'ENSKILD' => 'Fristående',
            'STAT' => 'Statlig',
            'REGION' => 'Region',
            default => $included['attributes']['organizerType'] ?? null,
        };

        // School types are code arrays like ["GR", "FKLASS"]
        $schoolTypeCodes = $attrs['schoolTypes'] ?? [];
        $schoolFormNames = array_map(
            fn (string $code) => self::SCHOOL_FORM_NAMES[$code] ?? $code,
            $schoolTypeCodes
        );

        return [
            'name' => $attrs['displayName'] ?? $attrs['schoolName'] ?? '',
            'status' => $status,
            'municipality_code' => $attrs['municipalityCode'] ?? null,
            'school_types' => $schoolTypeCodes,
            'type_of_schooling' => implode(', ', $schoolFormNames) ?: null,
            'school_forms' => $schoolFormNames,
            'operator_name' => $included['attributes']['displayName'] ?? null,
            'operator_type' => $organizerType,
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
        ];
    }

    /**
     * Fetch grundskola statistics for a school from Planned Educations v3.
     *
     * @return array{merit_value_17: float|null, goal_achievement_pct: float|null, eligibility_pct: float|null, teacher_certification_pct: float|null, student_count: int|null, academic_year: string|null}|null
     */
    public function fetchGrundskolaStats(string $schoolUnitCode): ?array
    {
        $url = self::PLANNED_EDU_BASE_URL.'/school-units/'.$schoolUnitCode.'/statistics/gr';

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => self::PLANNED_EDU_ACCEPT])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $this->parseGrundskolaStatsResponse($response->json());
    }

    /**
     * Parse a grundskola statistics API response into structured data (latest year only).
     *
     * @return array{merit_value_17: float|null, goal_achievement_pct: float|null, eligibility_pct: float|null, teacher_certification_pct: float|null, student_count: int|null, academic_year: string|null}|null
     */
    public function parseGrundskolaStatsResponse(array $responseData): ?array
    {
        $body = $responseData['body'] ?? [];

        $meritEntries = $body['averageGradesMeritRating9thGrade'] ?? [];
        $goalEntries = $body['ratioOfPupilsIn9thGradeWithAllSubjectsPassed'] ?? [];
        $eligibilityEntries = $body['ratioOfPupils9thGradeEligibleForNationalProgramYR'] ?? [];
        $teacherEntries = $body['certifiedTeachersQuota'] ?? [];
        $studentEntries = $body['totalNumberOfPupils'] ?? [];

        $merit = $this->getLatestValue($meritEntries);
        $goal = $this->getLatestValue($goalEntries);
        $eligibility = $this->getLatestValue($eligibilityEntries);
        $teacher = $this->getLatestValue($teacherEntries);
        $students = $this->getLatestStudentCount($studentEntries);
        $academicYear = $this->getLatestTimePeriod($meritEntries)
            ?? $this->getLatestTimePeriod($goalEntries)
            ?? $this->getLatestTimePeriod($teacherEntries);

        if ($merit === null && $goal === null && $eligibility === null && $teacher === null) {
            return null;
        }

        return [
            'merit_value_17' => $merit,
            'goal_achievement_pct' => $goal,
            'eligibility_pct' => $eligibility,
            'teacher_certification_pct' => $teacher,
            'student_count' => $students,
            'academic_year' => $academicYear,
        ];
    }

    /**
     * Parse ALL academic years from a grundskola statistics API response.
     *
     * @return array<string, array{merit_value_17: float|null, goal_achievement_pct: float|null, eligibility_pct: float|null, teacher_certification_pct: float|null, student_count: int|null}>
     */
    public function parseAllYearsGrundskolaStats(array $responseData): array
    {
        $body = $responseData['body'] ?? [];

        $fieldMap = [
            'averageGradesMeritRating9thGrade' => 'merit_value_17',
            'ratioOfPupilsIn9thGradeWithAllSubjectsPassed' => 'goal_achievement_pct',
            'ratioOfPupils9thGradeEligibleForNationalProgramYR' => 'eligibility_pct',
            'certifiedTeachersQuota' => 'teacher_certification_pct',
        ];

        $yearlyStats = [];

        foreach ($fieldMap as $apiField => $dbField) {
            $entries = $body[$apiField] ?? [];

            foreach ($entries as $entry) {
                if (($entry['valueType'] ?? '') !== 'EXISTS') {
                    continue;
                }

                $timePeriod = $entry['timePeriod'] ?? null;
                $value = $entry['value'] ?? null;

                if ($timePeriod === null || $value === null || $value === '.' || $value === '..') {
                    continue;
                }

                $parsed = (float) str_replace(',', '.', $value);
                $yearlyStats[$timePeriod][$dbField] = $parsed;
            }
        }

        // Student count — only available for certain years
        foreach ($body['totalNumberOfPupils'] ?? [] as $entry) {
            if (($entry['valueType'] ?? '') !== 'EXISTS') {
                continue;
            }

            $timePeriod = $entry['timePeriod'] ?? null;
            $value = $entry['value'] ?? null;

            if ($timePeriod === null || $value === null) {
                continue;
            }

            $number = preg_replace('/[^0-9]/', '', $value);
            if ($number !== '') {
                $yearlyStats[$timePeriod]['student_count'] = (int) $number;
            }
        }

        // Fill in null defaults for each year
        foreach ($yearlyStats as $year => &$stats) {
            $stats += [
                'merit_value_17' => null,
                'goal_achievement_pct' => null,
                'eligibility_pct' => null,
                'teacher_certification_pct' => null,
                'student_count' => null,
            ];
        }

        // Remove years where all stat values are null (only student_count)
        return array_filter($yearlyStats, function (array $stats) {
            return $stats['merit_value_17'] !== null
                || $stats['goal_achievement_pct'] !== null
                || $stats['eligibility_pct'] !== null
                || $stats['teacher_certification_pct'] !== null;
        });
    }

    /**
     * Parse a Swedish decimal value (comma separator) from the stats entries.
     * Only returns values where valueType is EXISTS.
     */
    private function getLatestValue(array $entries): ?float
    {
        $latest = null;
        foreach ($entries as $entry) {
            if (($entry['valueType'] ?? '') !== 'EXISTS') {
                continue;
            }

            $value = $entry['value'] ?? null;
            if ($value === null || $value === '.' || $value === '..') {
                continue;
            }

            // Swedish decimal: comma -> dot
            $parsed = (float) str_replace(',', '.', $value);
            $latest = $parsed;
        }

        return $latest;
    }

    private function getLatestStudentCount(array $entries): ?int
    {
        $latest = null;
        foreach ($entries as $entry) {
            if (($entry['valueType'] ?? '') !== 'EXISTS') {
                continue;
            }

            $value = $entry['value'] ?? null;
            if ($value === null) {
                continue;
            }

            // Value might be "cirka 560" — extract the number
            $number = preg_replace('/[^0-9]/', '', $value);
            if ($number !== '') {
                $latest = (int) $number;
            }
        }

        return $latest;
    }

    private function getLatestTimePeriod(array $entries): ?string
    {
        $latest = null;
        foreach ($entries as $entry) {
            if (($entry['valueType'] ?? '') === 'EXISTS') {
                $latest = $entry['timePeriod'] ?? $latest;
            }
        }

        return $latest;
    }
}
