<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ValidateIndicators extends Command
{
    protected $signature = 'validate:indicators
        {--year=2024 : The data year to validate}
        {--indicator= : Validate a specific indicator slug only}';

    protected $description = 'Run sanity checks against known reference points for indicator data';

    private int $passed = 0;

    private int $failed = 0;

    private int $skipped = 0;

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $indicatorFilter = $this->option('indicator');

        $checks = config('data_sanity_checks', []);

        if ($indicatorFilter) {
            $checks = array_filter($checks, fn ($_, $slug) => $slug === $indicatorFilter, ARRAY_FILTER_USE_BOTH);
            if (empty($checks)) {
                $this->warn("No sanity checks defined for indicator: {$indicatorFilter}");

                return self::SUCCESS;
            }
        }

        $this->info("Running sanity checks for year {$year}...");
        $this->newLine();

        foreach ($checks as $slug => $slugChecks) {
            $indicatorId = DB::table('indicators')->where('slug', $slug)->value('id');
            if (! $indicatorId) {
                $this->skipped++;
                $this->line("  <comment>SKIP</comment> {$slug}: indicator not found in database");

                continue;
            }

            foreach ($slugChecks as $check) {
                if (isset($check['kommun'])) {
                    $this->checkKommunAverage($slug, $indicatorId, $year, $check);
                } elseif (isset($check['national_median_min'])) {
                    $this->checkNationalMedian($slug, $indicatorId, $year, $check);
                }
            }
        }

        $this->newLine();
        $this->info("Results: {$this->passed} passed, {$this->failed} failed, {$this->skipped} skipped");

        if ($this->failed > 0) {
            $this->error('Sanity checks failed — data may be incorrect. Review the failures above.');

            return self::FAILURE;
        }

        $this->info('All sanity checks passed.');

        return self::SUCCESS;
    }

    private function checkKommunAverage(string $slug, int $indicatorId, int $year, array $check): void
    {
        $kommun = $check['kommun'];
        $min = $check['min'];
        $max = $check['max'];
        $label = $check['label'] ?? "{$slug} in {$kommun}";

        $avgValue = DB::table('indicator_values')
            ->join('deso_areas', 'deso_areas.deso_code', '=', 'indicator_values.deso_code')
            ->where('indicator_values.indicator_id', $indicatorId)
            ->where('indicator_values.year', $year)
            ->where('deso_areas.kommun_name', $kommun)
            ->whereNotNull('indicator_values.raw_value')
            ->avg('indicator_values.raw_value');

        if ($avgValue === null) {
            $this->skipped++;
            $this->line("  <comment>SKIP</comment> {$label}: no data for {$kommun}");

            return;
        }

        $avgRounded = round((float) $avgValue, 1);

        if ($avgRounded >= $min && $avgRounded <= $max) {
            $this->passed++;
            $this->line("  <info>PASS</info> {$label} = {$avgRounded} (expected {$min}–{$max})");
        } else {
            $this->failed++;
            $this->line("  <error>FAIL</error> {$label} = {$avgRounded} (expected {$min}–{$max})");
        }
    }

    private function checkNationalMedian(string $slug, int $indicatorId, int $year, array $check): void
    {
        $min = $check['national_median_min'];
        $max = $check['national_median_max'];
        $label = $check['label'] ?? "{$slug} national median";

        $median = DB::table('indicator_values')
            ->where('indicator_id', $indicatorId)
            ->where('year', $year)
            ->whereNotNull('raw_value')
            ->selectRaw('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY raw_value) as median_val')
            ->value('median_val');

        if ($median === null) {
            $this->skipped++;
            $this->line("  <comment>SKIP</comment> {$label}: no data");

            return;
        }

        $medianRounded = round((float) $median, 1);

        if ($medianRounded >= $min && $medianRounded <= $max) {
            $this->passed++;
            $this->line("  <info>PASS</info> {$label} = {$medianRounded} (expected {$min}–{$max})");
        } else {
            $this->failed++;
            $this->line("  <error>FAIL</error> {$label} = {$medianRounded} (expected {$min}–{$max})");
        }
    }
}
