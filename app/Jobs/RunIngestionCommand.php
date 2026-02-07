<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class RunIngestionCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public string $source,
        public string $command,
        public array $options = [],
        public string $triggeredBy = 'manual',
    ) {}

    public function handle(): void
    {
        Log::info("Pipeline: Running {$this->command}", [
            'source' => $this->source,
            'options' => $this->options,
            'triggered_by' => $this->triggeredBy,
        ]);

        $exitCode = Artisan::call($this->command, $this->options);
        $output = Artisan::output();

        if ($exitCode !== 0) {
            Log::warning("Pipeline: {$this->command} exited with code {$exitCode}", [
                'output' => $output,
            ]);
        }
    }
}
