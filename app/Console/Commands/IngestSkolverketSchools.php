<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Services\SkolverketApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class IngestSkolverketSchools extends Command
{
    use LogsIngestion;

    protected $signature = 'ingest:skolverket-schools
        {--delay=100 : Delay between batch requests in milliseconds}
        {--batch-size=10 : Number of concurrent detail requests per batch}
        {--force : Re-fetch and overwrite all school data from the API}
        {--skip-details : Skip fetching individual school details (coordinates)}
        {--include-ceased : Include ceased (upphörda) school units}';

    protected $description = 'Ingest all school units from Skolverket Registry API v2';

    private const REGISTRY_BASE_URL = 'https://api.skolverket.se/skolenhetsregistret/v2';

    public function handle(): int
    {
        $delay = (int) $this->option('delay');
        $batchSize = (int) $this->option('batch-size');
        $service = new SkolverketApiService($delay);

        $this->startIngestionLog('skolverket_schools', 'ingest:skolverket-schools');

        try {
            // Step 1: Fetch all school units from Registry v2
            $statuses = $this->option('include-ceased')
                ? null
                : ['AKTIV', 'VILANDE', 'PLANERAD'];

            $statusLabel = $this->option('include-ceased') ? 'all statuses' : 'active/dormant/planned';
            $this->info("Fetching school unit list from Registry v2 ({$statusLabel})...");

            $schools = $service->fetchAllSchoolUnits($statuses);

            if (empty($schools)) {
                $this->error('No school units returned from API. Aborting.');
                $this->failIngestionLog('Empty response from Registry v2 list endpoint');

                return self::FAILURE;
            }

            $this->info('Retrieved '.count($schools).' school units from Registry v2.');

            // Step 2: Upsert minimal data from list (code, name, status)
            $now = now()->toDateTimeString();
            $rows = [];
            foreach ($schools as $school) {
                $status = match ($school['status']) {
                    'AKTIV' => 'active',
                    'VILANDE' => 'dormant',
                    'UPPHORT' => 'ceased',
                    'PLANERAD' => 'planned',
                    default => 'active',
                };

                $rows[] = [
                    'school_unit_code' => $school['code'],
                    'name' => $school['name'],
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('schools')->upsert(
                    $chunk,
                    ['school_unit_code'],
                    ['name', 'status', 'updated_at']
                );
            }

            $this->info('Upserted '.count($rows).' school units.');

            // Step 3: Fetch individual school details for all data
            if (! $this->option('skip-details')) {
                $this->info("Fetching school details in batches of {$batchSize}...");
                $this->fetchSchoolDetailsBatched($schools, $batchSize, $delay);
            }

            // Step 4: Spatial join — assign DeSO codes
            $this->info('Assigning DeSO codes via spatial join...');
            $this->assignDesoCodes();

            // Log comprehensive stats
            $this->processed = count($schools);
            $this->logStats(count($schools));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->failIngestionLog($e->getMessage());
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  array<int, array{code: string, name: string, status: string}>  $schools
     */
    private function fetchSchoolDetailsBatched(array $schools, int $batchSize, int $delay): void
    {
        $bar = $this->output->createProgressBar(count($schools));
        $bar->start();

        $updated = 0;
        $failed = 0;
        $now = now()->toDateTimeString();
        $service = new SkolverketApiService;

        $batches = array_chunk($schools, $batchSize);

        foreach ($batches as $batch) {
            $responses = Http::pool(function ($pool) use ($batch) {
                foreach ($batch as $school) {
                    $pool->as($school['code'])
                        ->timeout(15)
                        ->acceptJson()
                        ->get(self::REGISTRY_BASE_URL.'/school-units/'.$school['code']);
                }
            });

            $bulkUpdates = [];

            foreach ($batch as $school) {
                $code = $school['code'];

                try {
                    $response = $responses[$code] ?? null;

                    if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                        $details = $service->parseSchoolDetails($response->json());

                        if ($details) {
                            $bulkUpdates[] = [
                                'code' => $code,
                                'details' => $details,
                            ];
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

            // Batch update all schools in this chunk
            foreach ($bulkUpdates as $item) {
                $d = $item['details'];
                DB::table('schools')
                    ->where('school_unit_code', $item['code'])
                    ->update([
                        'name' => $d['name'],
                        'municipality_code' => $d['municipality_code'],
                        'type_of_schooling' => $d['type_of_schooling'],
                        'school_forms' => json_encode($d['school_forms']),
                        'operator_name' => $d['operator_name'],
                        'operator_type' => $d['operator_type'],
                        'status' => $d['status'],
                        'lat' => $d['lat'],
                        'lng' => $d['lng'],
                        'address' => $d['address'],
                        'postal_code' => $d['postal_code'],
                        'city' => $d['city'],
                        'updated_at' => $now,
                    ]);

                if ($d['lat'] !== null && $d['lng'] !== null) {
                    DB::statement(
                        'UPDATE schools SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE school_unit_code = ?',
                        [$d['lng'], $d['lat'], $item['code']]
                    );
                }
            }

            usleep($delay * 1000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Details fetched: {$updated} updated, {$failed} failed/missing.");
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

    private function logStats(int $totalFromApi): void
    {
        $dbStats = DB::select("
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE status = 'active') as active,
                COUNT(*) FILTER (WHERE status = 'ceased') as ceased,
                COUNT(*) FILTER (WHERE status = 'dormant') as dormant,
                COUNT(*) FILTER (WHERE status = 'planned') as planned,
                COUNT(*) FILTER (WHERE lat IS NOT NULL) as with_coords,
                COUNT(*) FILTER (WHERE lat IS NULL) as without_coords,
                COUNT(*) FILTER (WHERE deso_code IS NOT NULL) as with_deso
            FROM schools
        ")[0];

        // School forms breakdown
        $formBreakdown = DB::select('
            SELECT form, COUNT(*) as cnt
            FROM schools, json_array_elements_text(school_forms) AS form
            WHERE school_forms IS NOT NULL
            GROUP BY form
            ORDER BY cnt DESC
        ');

        $this->created = $dbStats->total;
        $this->addStat('total_schools', $dbStats->total);
        $this->addStat('active', $dbStats->active);
        $this->addStat('ceased', $dbStats->ceased);
        $this->addStat('dormant', $dbStats->dormant);
        $this->addStat('planned', $dbStats->planned);
        $this->addStat('with_coordinates', $dbStats->with_coords);
        $this->addStat('without_coordinates', $dbStats->without_coords);
        $this->addStat('with_deso_code', $dbStats->with_deso);

        $this->completeIngestionLog();

        $this->newLine();
        $this->info("Total school units in database: {$dbStats->total}");
        $this->info("  - Active: {$dbStats->active}");
        $this->info("  - Dormant: {$dbStats->dormant}");
        $this->info("  - Ceased: {$dbStats->ceased}");
        $this->info("  - Planned: {$dbStats->planned}");
        $this->info("  - With coordinates: {$dbStats->with_coords}");
        $this->info("  - Without coordinates: {$dbStats->without_coords}");
        $this->info("  - DeSO assigned: {$dbStats->with_deso}");

        if (! empty($formBreakdown)) {
            $this->newLine();
            $this->info('School forms breakdown:');
            foreach ($formBreakdown as $form) {
                $this->info("  - {$form->form}: {$form->cnt}");
            }
        }
    }
}
