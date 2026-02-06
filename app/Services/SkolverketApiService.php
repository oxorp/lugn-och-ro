<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SkolverketApiService
{
    private const REGISTRY_BASE_URL = 'https://api.skolverket.se/skolenhetsregistret/v2';

    private const PLANNED_EDU_BASE_URL = 'https://api.skolverket.se/planned-educations/v3';

    private const PLANNED_EDU_ACCEPT = 'application/vnd.skolverket.plannededucations.api.v3.hal+json';

    private int $delayMs;

    public function __construct(int $delayMs = 100)
    {
        $this->delayMs = $delayMs;
    }

    /**
     * Fetch all schools from the Planned Educations v3 paginated list.
     *
     * @return array<int, array{code: string, name: string, municipality_code: string, type_of_schooling: string|null, principal_organizer_type: string|null}>
     */
    public function fetchAllSchools(): array
    {
        $schools = [];
        $page = 0;
        $size = 100;
        $totalPages = 1;

        while ($page < $totalPages) {
            $response = Http::timeout(60)
                ->withHeaders(['Accept' => self::PLANNED_EDU_ACCEPT])
                ->get(self::PLANNED_EDU_BASE_URL.'/school-units', [
                    'page' => $page,
                    'size' => $size,
                ]);

            if (! $response->successful()) {
                Log::warning("Planned Educations list failed on page {$page}: {$response->status()}", [
                    'body' => substr($response->body(), 0, 500),
                ]);

                break;
            }

            $data = $response->json();
            $body = $data['body'] ?? [];
            $listed = $body['_embedded']['listedSchoolUnits'] ?? [];
            $pageInfo = $body['page'] ?? [];

            $totalPages = $pageInfo['totalPages'] ?? 1;

            foreach ($listed as $school) {
                $types = collect($school['typeOfSchooling'] ?? [])
                    ->pluck('displayName')
                    ->join(', ');

                $schools[] = [
                    'code' => $school['code'],
                    'name' => $school['name'],
                    'municipality_code' => $school['geographicalAreaCode'] ?? null,
                    'type_of_schooling' => $types ?: null,
                    'principal_organizer_type' => $school['principalOrganizerType'] ?? null,
                    'has_grundskola' => collect($school['typeOfSchooling'] ?? [])
                        ->contains(fn ($t) => strtolower($t['code'] ?? '') === 'gr'),
                ];
            }

            $page++;

            if ($page < $totalPages) {
                usleep($this->delayMs * 1000);
            }
        }

        return $schools;
    }

    /**
     * Fetch a single school's details (including coordinates) from the registry v2.
     *
     * @return array{lat: float|null, lng: float|null, address: string|null, postal_code: string|null, city: string|null, operator_name: string|null, operator_type: string|null, status: string}|null
     */
    public function fetchSchoolDetails(string $schoolUnitCode): ?array
    {
        $response = Http::timeout(30)
            ->get(self::REGISTRY_BASE_URL.'/school-units/'.$schoolUnitCode);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $attrs = $data['data']['attributes'] ?? [];
        $included = $data['included'] ?? [];

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

        $status = match ($attrs['status'] ?? 'AKTIV') {
            'AKTIV' => 'active',
            'VILANDE' => 'inactive',
            'UPPHORT' => 'inactive',
            default => 'active',
        };

        $organizerType = match ($included['attributes']['organizerType'] ?? null) {
            'KOMMUN' => 'Kommunal',
            'ENSKILD' => 'Fristående',
            'STAT' => 'Statlig',
            'REGION' => 'Region',
            default => $included['attributes']['organizerType'] ?? null,
        };

        return [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'postal_code' => $postalCode,
            'city' => $city,
            'operator_name' => $included['attributes']['displayName'] ?? null,
            'operator_type' => $organizerType,
            'status' => $status,
        ];
    }

    /**
     * Fetch grundskola statistics for a school.
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
     * Parse a grundskola statistics API response into structured data.
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
     * Parse a Swedish decimal value (comma separator) from the stats entries.
     * Only returns values where valueType is EXISTS.
     */
    private function getLatestValue(array $entries): ?float
    {
        // Entries are time-series, get the latest with a valid value
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
            $latest = $parsed; // Last valid entry is the latest
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
