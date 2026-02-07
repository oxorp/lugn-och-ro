<?php

namespace App\Console\Commands;

use App\Models\Poi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignPoiDeso extends Command
{
    protected $signature = 'assign:poi-deso
        {--force : Re-assign all POIs, not just unassigned ones}';

    protected $description = 'Assign DeSO codes to POIs via PostGIS spatial join';

    public function handle(): int
    {
        $this->info('Assigning DeSO codes to POIs...');

        $whereClause = $this->option('force') ? '' : 'AND p.deso_code IS NULL';

        $updated = DB::update("
            UPDATE pois p
            SET deso_code = d.deso_code,
                municipality_code = d.kommun_code
            FROM deso_areas d
            WHERE ST_Contains(d.geom, p.geom)
              AND p.geom IS NOT NULL
              {$whereClause}
        ");

        $this->info("Assigned DeSO codes to {$updated} POIs.");

        // Log unassigned
        $unassigned = Poi::query()
            ->whereNull('deso_code')
            ->whereNotNull('lat')
            ->count();

        if ($unassigned > 0) {
            $this->warn("{$unassigned} POIs could not be assigned to a DeSO (coordinates outside Sweden boundaries).");
        }

        // Summary by category
        $summary = DB::select("
            SELECT category, COUNT(*) AS total,
                   COUNT(deso_code) AS assigned,
                   COUNT(*) - COUNT(deso_code) AS unassigned
            FROM pois
            WHERE status = 'active'
            GROUP BY category
            ORDER BY category
        ");

        $this->table(
            ['Category', 'Total', 'Assigned', 'Unassigned'],
            collect($summary)->map(fn ($r) => [$r->category, $r->total, $r->assigned, $r->unassigned])->all()
        );

        return self::SUCCESS;
    }
}
