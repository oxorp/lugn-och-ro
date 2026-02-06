<?php

namespace App\Console\Commands;

use App\Models\IngestionLog;
use App\Models\School;
use App\Services\SkolverketApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class IngestSkolverketStats extends Command
{
    protected $signature = 'ingest:skolverket-stats
        {--delay=200 : Delay between batch requests in milliseconds}
        {--batch-size=10 : Number of concurrent requests per batch}
        {--limit=0 : Limit number of schools to fetch (0 = all)}';

    protected $description = 'Ingest school performance statistics from Skolverket Planned Educations API';

    private const PLANNED_EDU_BASE_URL = 'https://api.skolverket.se/planned-educations/v3';

    private const PLANNED_EDU_ACCEPT = 'application/vnd.skolverket.plannededucations.api.v3.hal+json';

    public function handle(): int
    {
        $delay = (int) $this->option('delay');
        $batchSize = (int) $this->option('batch-size');
        $limit = (int) $this->option('limit');
        $service = new SkolverketApiService($delay);

        $log = IngestionLog::query()->create([
            'source' => 'skolverket',
            'command' => 'ingest:skolverket-stats',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $query = School::query()
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->where('type_of_schooling', 'like', '%Grundskolan%')
                        ->orWhere('type_of_schooling', 'like', '%Grundskola%');
                });

            if ($limit > 0) {
                $query->limit($limit);
            }

            $schoolCodes = $query->pluck('school_unit_code')->toArray();
            $this->info('Found '.count($schoolCodes).' grundskola schools to fetch statistics for.');

            $bar = $this->output->createProgressBar(count($schoolCodes));
            $bar->start();

            $rows = [];
            $fetched = 0;
            $noData = 0;
            $failed = 0;
            $now = now()->toDateTimeString();

            $batches = array_chunk($schoolCodes, $batchSize);

            foreach ($batches as $batch) {
                $responses = Http::pool(function ($pool) use ($batch) {
                    foreach ($batch as $code) {
                        $pool->as($code)
                            ->timeout(15)
                            ->withHeaders(['Accept' => self::PLANNED_EDU_ACCEPT])
                            ->get(self::PLANNED_EDU_BASE_URL.'/school-units/'.$code.'/statistics/gr');
                    }
                });

                foreach ($batch as $code) {
                    try {
                        $response = $responses[$code] ?? null;

                        if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                            $stats = $service->parseGrundskolaStatsResponse($response->json());

                            if ($stats && $stats['academic_year']) {
                                $rows[] = [
                                    'school_unit_code' => $code,
                                    'academic_year' => $stats['academic_year'],
                                    'merit_value_17' => $stats['merit_value_17'],
                                    'goal_achievement_pct' => $stats['goal_achievement_pct'],
                                    'eligibility_pct' => $stats['eligibility_pct'],
                                    'teacher_certification_pct' => $stats['teacher_certification_pct'],
                                    'student_count' => $stats['student_count'],
                                    'data_source' => 'planned_educations_v3',
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                                $fetched++;
                            } else {
                                $noData++;
                            }
                        } else {
                            $failed++;
                        }
                    } catch (\Throwable) {
                        $failed++;
                    }

                    $bar->advance();
                }

                // Bulk upsert every 500 rows
                if (count($rows) >= 500) {
                    $this->upsertRows($rows);
                    $rows = [];
                }

                usleep($delay * 1000);
            }

            // Final batch
            if (count($rows) > 0) {
                $this->upsertRows($rows);
            }

            $bar->finish();
            $this->newLine();

            $totalStats = DB::table('school_statistics')->count();

            $log->update([
                'status' => 'completed',
                'records_processed' => count($schoolCodes),
                'records_created' => $fetched,
                'completed_at' => now(),
                'metadata' => [
                    'schools_processed' => count($schoolCodes),
                    'stats_fetched' => $fetched,
                    'no_data' => $noData,
                    'failed' => $failed,
                    'total_stats_rows' => $totalStats,
                ],
            ]);

            $this->info("Stats complete: {$fetched} schools with data, {$noData} no data, {$failed} failed.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error("Stats ingestion failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertRows(array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('school_statistics')->upsert(
                $chunk,
                ['school_unit_code', 'academic_year'],
                ['merit_value_17', 'merit_value_16', 'goal_achievement_pct', 'eligibility_pct', 'teacher_certification_pct', 'student_count', 'data_source', 'updated_at']
            );
        }
    }
}
