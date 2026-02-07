<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GeocodeSchools extends Command
{
    protected $signature = 'geocode:schools
        {--source=nominatim : Geocoding source (nominatim)}
        {--limit=0 : Limit number of schools to geocode (0 = all)}
        {--delay=1100 : Delay between requests in milliseconds (Nominatim requires 1/sec)}';

    protected $description = 'Geocode schools that have addresses but no coordinates';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('delay');

        $query = DB::table('schools')
            ->whereNull('lat')
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->select('school_unit_code', 'name', 'address', 'postal_code', 'city');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $schools = $query->get();
        $this->info("Found {$schools->count()} schools without coordinates to geocode.");

        if ($schools->isEmpty()) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($schools->count());
        $bar->start();

        $geocoded = 0;
        $failed = 0;

        foreach ($schools as $school) {
            $result = $this->geocodeWithNominatim($school);

            if ($result) {
                DB::table('schools')
                    ->where('school_unit_code', $school->school_unit_code)
                    ->update([
                        'lat' => $result['lat'],
                        'lng' => $result['lng'],
                        'updated_at' => now(),
                    ]);

                DB::statement(
                    'UPDATE schools SET geom = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE school_unit_code = ?',
                    [$result['lng'], $result['lat'], $school->school_unit_code]
                );

                $geocoded++;
            } else {
                $failed++;
            }

            $bar->advance();
            usleep($delay * 1000);
        }

        $bar->finish();
        $this->newLine();

        $this->info("Geocoded: {$geocoded}, Failed: {$failed}");

        // Re-run DeSO spatial join for newly geocoded schools
        if ($geocoded > 0) {
            $this->info('Re-running DeSO spatial join for geocoded schools...');
            $assigned = DB::affectingStatement('
                UPDATE schools s
                SET deso_code = d.deso_code
                FROM deso_areas d
                WHERE ST_Contains(d.geom, s.geom)
                  AND s.geom IS NOT NULL
                  AND s.deso_code IS NULL
            ');
            $this->info("Assigned DeSO codes to {$assigned} newly geocoded schools.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function geocodeWithNominatim(object $school): ?array
    {
        $parts = array_filter([
            $school->address,
            $school->postal_code,
            $school->city,
            'Sweden',
        ]);

        $query = implode(', ', $parts);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'LugnOchRo/1.0 (school geocoding)',
                ])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'se',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $results = $response->json();

            if (empty($results)) {
                return null;
            }

            return [
                'lat' => (float) $results[0]['lat'],
                'lng' => (float) $results[0]['lon'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
