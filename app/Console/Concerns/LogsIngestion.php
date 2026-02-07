<?php

namespace App\Console\Concerns;

use App\Models\IngestionLog;

trait LogsIngestion
{
    protected ?IngestionLog $ingestionLog = null;

    protected int $processed = 0;

    protected int $created = 0;

    protected int $updated = 0;

    protected int $failed = 0;

    protected int $skipped = 0;

    /** @var string[] */
    protected array $warnings = [];

    /** @var array<string, mixed> */
    protected array $stats = [];

    protected function startIngestionLog(string $source, string $command): void
    {
        $this->ingestionLog = IngestionLog::create([
            'source' => $source,
            'command' => $command,
            'status' => 'running',
            'trigger' => app()->runningInConsole() ? 'cli' : 'queue',
            'started_at' => now(),
        ]);
    }

    protected function completeIngestionLog(?string $summary = null): void
    {
        $this->ingestionLog?->update([
            'status' => 'completed',
            'completed_at' => now(),
            'records_processed' => $this->processed,
            'records_created' => $this->created,
            'records_updated' => $this->updated,
            'records_failed' => $this->failed,
            'records_skipped' => $this->skipped,
            'warnings' => $this->warnings ?: null,
            'stats' => $this->stats ?: null,
            'summary' => $summary ?? $this->buildSummary(),
            'duration_seconds' => (int) $this->ingestionLog->started_at->diffInSeconds(now()),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);
    }

    protected function failIngestionLog(string $error): void
    {
        $this->ingestionLog?->update([
            'status' => 'failed',
            'completed_at' => now(),
            'records_processed' => $this->processed,
            'records_failed' => $this->failed,
            'error_message' => $error,
            'summary' => "Failed: {$error}",
            'duration_seconds' => (int) $this->ingestionLog->started_at->diffInSeconds(now()),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);
    }

    protected function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
        $this->warn($warning);
    }

    protected function addStat(string $key, mixed $value): void
    {
        $this->stats[$key] = $value;
    }

    private function buildSummary(): string
    {
        $parts = ["Processed: {$this->processed}"];
        if ($this->created > 0) {
            $parts[] = "Created: {$this->created}";
        }
        if ($this->updated > 0) {
            $parts[] = "Updated: {$this->updated}";
        }
        if ($this->failed > 0) {
            $parts[] = "Failed: {$this->failed}";
        }
        if ($this->skipped > 0) {
            $parts[] = "Skipped: {$this->skipped}";
        }
        if (count($this->warnings) > 0) {
            $parts[] = 'Warnings: '.count($this->warnings);
        }

        return implode(' | ', $parts);
    }
}
