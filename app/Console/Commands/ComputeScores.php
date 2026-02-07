<?php

namespace App\Console\Commands;

use App\Console\Concerns\LogsIngestion;
use App\Models\Tenant;
use App\Services\ScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ComputeScores extends Command
{
    use LogsIngestion;

    protected $signature = 'compute:scores
        {--year= : Year to compute scores for (defaults to current year)}
        {--tenant= : Tenant UUID to compute scores for (omit for public/default scores)}
        {--all-tenants : Compute scores for all tenants}';

    protected $description = 'Compute composite neighborhood scores from normalized indicator values';

    public function handle(ScoringService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $this->startIngestionLog('scoring', 'compute:scores');

        try {
            $tenant = null;

            if ($this->option('all-tenants')) {
                return $this->computeForAllTenants($service, $year);
            }

            if ($this->option('tenant')) {
                $tenant = Tenant::where('uuid', $this->option('tenant'))->first();
                if (! $tenant) {
                    $this->error("Tenant not found: {$this->option('tenant')}");

                    return self::FAILURE;
                }
                $this->info("Computing scores for tenant: {$tenant->uuid}");
            }

            $this->info("Computing composite scores for year {$year}...");

            $count = $service->computeScores($year, $tenant);
            $this->processed = $count;
            $this->updated = $count;
            $this->addStat('year', $year);
            $this->addStat('desos_scored', $count);

            $this->info("Computed scores for {$count} DeSO areas.");

            // Project to H3 and apply smoothing if the mapping table exists
            if (Schema::hasTable('deso_h3_mapping')) {
                $mappingCount = DB::table('deso_h3_mapping')->count();
                if ($mappingCount > 0) {
                    $this->call('project:scores-to-h3', ['--year' => $year]);
                    $this->call('smooth:h3-scores', ['--year' => $year, '--config' => 'Light']);
                    $this->addStat('h3_projected', true);
                }
            }

            $this->completeIngestionLog();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failIngestionLog($e->getMessage());
            $this->error("Score computation failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function computeForAllTenants(ScoringService $service, int $year): int
    {
        // First compute public/default scores
        $this->info('Computing default (public) scores...');
        $count = $service->computeScores($year);
        $this->info("Default: {$count} DeSOs scored.");

        // Then compute per-tenant
        $tenants = Tenant::all();
        foreach ($tenants as $tenant) {
            $this->info("Computing scores for tenant {$tenant->uuid}...");
            $tenantCount = $service->computeScores($year, $tenant);
            $this->info("Tenant {$tenant->uuid}: {$tenantCount} DeSOs scored.");
        }

        $this->completeIngestionLog();

        return self::SUCCESS;
    }
}
