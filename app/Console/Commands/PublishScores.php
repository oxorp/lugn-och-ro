<?php

namespace App\Console\Commands;

use App\Models\ScoreVersion;
use Illuminate\Console\Command;

class PublishScores extends Command
{
    protected $signature = 'scores:publish
        {--score-version= : Score version ID to publish}
        {--year= : Publish the latest validated version for this year}';

    protected $description = 'Publish a validated score version, making it live on the API';

    public function handle(): int
    {
        $version = $this->resolveVersion();

        if (! $version) {
            $this->error('No version found to publish.');

            return self::FAILURE;
        }

        if (! in_array($version->status, ['pending', 'validated'])) {
            $this->error("Version #{$version->id} has status '{$version->status}' — can only publish 'pending' or 'validated' versions.");

            return self::FAILURE;
        }

        // Unpublish any previously published version for this year
        ScoreVersion::query()
            ->where('year', $version->year)
            ->where('status', 'published')
            ->update(['status' => 'superseded']);

        $version->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->info("Score version #{$version->id} published for year {$version->year}.");
        $this->info("  DeSOs: {$version->deso_count}, Mean: {$version->mean_score}, StdDev: {$version->stddev_score}");

        return self::SUCCESS;
    }

    private function resolveVersion(): ?ScoreVersion
    {
        if ($id = $this->option('score-version')) {
            return ScoreVersion::query()->find($id);
        }

        $year = (int) ($this->option('year') ?: now()->year - 1);

        return ScoreVersion::query()
            ->where('year', $year)
            ->whereIn('status', ['validated', 'pending'])
            ->latest('computed_at')
            ->first();
    }
}
