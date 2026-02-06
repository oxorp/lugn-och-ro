<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use App\Services\SkolverketApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class IngestSkolverketSchools extends Command
{
    protected $signature = 'ingest:skolverket-schools
        {--delay=100 : Delay between batch requests in milliseconds}
        {--skip-details : Skip fetching individual school details (coordinates)}
        {--batch-size=10 : Number of concurrent requests per batch}';

    protected $description = 'Ingest school locations and metadata from Skolverket APIs';

    private const REGISTRY_BASE_URL = 'https://api.skolverket.se/skolenhetsregistret/v2';

    public function handle(): int
    {
        $delay = (int) $this->option('delay');
        $batchSize = (int) $this->option('batch-size');
        $service = new SkolverketApiService($delay);

        $log = IngestionLog::query()->create([
            'source' => 'skolverket',
            'command' => 'ingest:skolverket-schools',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // Step 1: Fetch all schools from Planned Educations v3 (paginated list)
            $this->info('Fetching school list from Planned Educations API...');
            $schools = $service->fetchAllSchools();
            $this->info('Retrieved '.count($schools).' schools from API.');

            // Step 2: Upsert basic school data
            $this->info('Upserting school data...');
            $now = now()->toDateTimeString();
            $rows = [];

            foreach ($schools as $school) {
                $rows[] = [
                    'school_unit_code' => $school['code'],
                    'name' => $school['name'],
                    'municipality_code' => $school['municipality_code'],
                    'type_of_schooling' => $school['type_of_schooling'],
                    'operator_type' => $school['principal_organizer_type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('schools')->upsert(
                    $chunk,
                    ['school_unit_code'],
                    ['name', 'municipality_code', 'type_of_schooling', 'operator_type', 'updated_at']
                );
            }

            $this->info('Upserted '.count($rows).' schools.');

            // Step 3: Fetch individual school details for coordinates (batched/concurrent)
            if (! $this->option('skip-details')) {
                $this->info("Fetching school details in batches of {$batchSize}...");
                $this->fetchSchoolDetailsBatched($schools, $batchSize, $delay);
            }

            // Step 4: Spatial join — assign DeSO codes
            $this->info('Assigning DeSO codes via spatial join...');
            $this->assignDesoCodes();

            $totalSchools = DB::table('schools')->count();
            $withCoords = DB::table('schools')->whereNotNull('lat')->count();
            $withDeso = DB::table('schools')->whereNotNull('deso_code')->count();

            $log->update([
                'status' => 'completed',
                'records_processed' => count($schools),
                'records_created' => $totalSchools,
                'completed_at' => now(),
                'metadata' => [
                    'total_schools' => $totalSchools,
                    'with_coordinates' => $withCoords,
                    'with_deso_code' => $withDeso,
                ],
            ]);

            $this->newLine();
            $this->info("Import complete: {$totalSchools} total schools, {$withCoords} with coordinates, {$withDeso} with DeSO assignment.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function fetchSchoolDetailsBatched(array $schools, int $batchSize, int $delay): void
    {
        $bar = $this->output->createProgressBar(count($schools));
        $bar->start();

        $updated = 0;
        $failed = 0;
        $now = now()->toDateTimeString();

        $batches = array_chunk($schools, $batchSize);

        foreach ($batches as $batch) {
            $responses = Http::pool(function ($pool) use ($batch) {
                foreach ($batch as $school) {
                    $pool->as($school['code'])
                        ->timeout(15)
                        ->get(self::REGISTRY_BASE_URL.'/school-units/'.$school['code']);
                }
            });

            foreach ($batch as $school) {
                $code = $school['code'];

                try {
                    $response = $responses[$code] ?? null;

                    if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                        $details = $this->parseSchoolDetails($response->json());

                        if ($details) {
                            $updateData = [
                                'lat' => $details['lat'],
                                'lng' => $details['lng'],
                                'address' => $details['address'],
                                'postal_code' => $details['postal_code'],
                                'city' => $details['city'],
                                'operator_name' => $details['operator_name'],
                                'status' => $details['status'],
                                'updated_at' => $now,
                            ];

                            if ($details['operator_type']) {
                                $updateData['operator_type'] = $details['operator_type'];
                            }

                            DB::table('schools')
                                ->where('school_unit_code', $code)
                                ->update($updateData);

                            if ($details['lat'] !== null && $details['lng'] !== null) {
                                DB::statement(
                                    'UPDATE schools SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE school_unit_code = ?',
                                    [$details['lng'], $details['lat'], $code]
                                );
                            }

                            $updated++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $failed++;
                    }
                } catch (\Throwable) {
                    $failed++;
                }

                $bar->advance();
            }

            usleep($delay * 1000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Details fetched: {$updated} updated, {$failed} failed/missing.");
    }

    /**
     * @return array{lat: float|null, lng: float|null, address: string|null, postal_code: string|null, city: string|null, operator_name: string|null, operator_type: string|null, status: string}|null
     */
    private function parseSchoolDetails(array $data): ?array
    {
        $attrs = $data['data']['attributes'] ?? [];
        $included = $data['included'] ?? [];

        if (empty($attrs)) {
            return null;
        }

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

    private function assignDesoCodes(): void
    {
        $assigned = DB::affectingStatement('
            UPDATE schools s
            SET deso_code = d.deso_code
            FROM deso_areas d
            WHERE ST_Contains(d.geom, s.geom)
              AND s.geom IS NOT NULL
        ');

        $this->info("Assigned DeSO codes to {$assigned} schools.");
    }
}
