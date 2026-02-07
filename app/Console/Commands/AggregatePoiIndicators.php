<?php

namespace App\Console\Commands;

use App\Jobs\AggregatePoiCategoryJob;
use App\Models\Indicator;
use App\Models\PoiCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregatePoiIndicators extends Command
{
    protected $signature = 'aggregate:poi-indicators
        {--year= : Year for the indicator values (defaults to current year)}
        {--category= : Specific category slug to aggregate}
        {--sync : Run synchronously instead of dispatching to queue}';

    protected $description = 'Aggregate POI data to DeSO-level catchment-based indicators';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->year);

        $query = PoiCategory::query()
            ->where('is_active', true)
            ->whereNotNull('indicator_slug');

        if ($this->option('category')) {
            $query->where('slug', $this->option('category'));
        }

        $categories = $query->get();

        if ($categories->isEmpty()) {
            $this->error('No matching active POI categories with indicator slugs found.');

            return self::FAILURE;
        }

        // Verify all indicators exist before dispatching
        foreach ($categories as $category) {
            $indicator = Indicator::query()->where('slug', $category->indicator_slug)->first();
            if (! $indicator) {
                $this->warn("Indicator '{$category->indicator_slug}' not found, skipping {$category->slug}.");
            }
        }

        $validCategories = $categories->filter(function (PoiCategory $cat) {
            return Indicator::query()->where('slug', $cat->indicator_slug)->exists();
        });

        if ($validCategories->isEmpty()) {
            $this->error('No categories with valid indicators found.');

            return self::FAILURE;
        }

        $this->info("Aggregating {$validCategories->count()} POI categories for year {$year}");

        if ($this->option('sync')) {
            return $this->runSync($validCategories, $year);
        }

        return $this->runAsync($validCategories, $year);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PoiCategory>  $categories
     */
    private function runSync(\Illuminate\Database\Eloquent\Collection $categories, int $year): int
    {
        $totalStart = microtime(true);

        foreach ($categories as $category) {
            $catStart = microtime(true);
            $this->info("Aggregating: {$category->name} (catchment {$category->catchment_km} km)");

            $job = new AggregatePoiCategoryJob($category->slug, $year);
            $job->handle();

            $elapsed = round(microtime(true) - $catStart, 1);
            $count = DB::table('indicator_values')
                ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
                ->where('indicators.slug', $category->indicator_slug)
                ->where('indicator_values.year', $year)
                ->count();

            $nonZero = DB::table('indicator_values')
                ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
                ->where('indicators.slug', $category->indicator_slug)
                ->where('indicator_values.year', $year)
                ->where('indicator_values.raw_value', '>', 0)
                ->count();

            $this->info("  → {$category->indicator_slug}: {$count} DeSOs, {$nonZero} with POIs nearby ({$elapsed}s)");
        }

        $totalElapsed = round(microtime(true) - $totalStart, 1);
        $this->newLine();
        $this->info("POI aggregation complete in {$totalElapsed}s. Run normalize:indicators and compute:scores to update composite scores.");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PoiCategory>  $categories
     */
    private function runAsync(\Illuminate\Database\Eloquent\Collection $categories, int $year): int
    {
        $jobs = $categories->map(fn (PoiCategory $cat) => new AggregatePoiCategoryJob($cat->slug, $year));

        $batch = Bus::batch($jobs->all())
            ->name("POI Aggregation {$year}")
            ->onQueue('default')
            ->dispatch();

        $this->info("Dispatched batch '{$batch->id}' with {$jobs->count()} jobs to queue.");
        $this->info('Categories: '.$categories->pluck('slug')->join(', '));
        $this->info('Monitor with: php artisan horizon or check logs.');

        Log::info('POI aggregation batch dispatched', [
            'batch_id' => $batch->id,
            'year' => $year,
            'categories' => $categories->pluck('slug')->all(),
        ]);

        return self::SUCCESS;
    }
}
