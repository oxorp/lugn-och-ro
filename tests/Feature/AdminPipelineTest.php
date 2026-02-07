<?php

namespace Tests\Feature;

use App\Console\Concerns\LogsIngestion;
use App\Jobs\RunFullPipeline;
use App\Jobs\RunIngestionCommand;
use App\Models\IngestionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_pipeline_page_loads(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/pipeline')
            ->has('sources')
            ->has('overallHealth')
            ->has('stats')
            ->has('pipelineOrder')
            ->has('recentLogs')
        );
    }

    public function test_pipeline_page_shows_all_configured_sources(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('sources', count(config('pipeline.sources')))
        );
    }

    public function test_pipeline_source_page_loads(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.pipeline.show', 'scb'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/pipeline-source')
            ->has('source')
            ->has('logs')
            ->has('indicators')
        );
    }

    public function test_pipeline_source_page_404_for_unknown_source(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.pipeline.show', 'nonexistent'));

        $response->assertNotFound();
    }

    public function test_pipeline_health_is_unknown_when_never_run(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('sources.0.health', 'unknown')
        );
    }

    public function test_pipeline_health_is_healthy_after_successful_run(): void
    {
        IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('sources.0.health', 'healthy')
        );
    }

    public function test_pipeline_shows_running_state(): void
    {
        IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('sources.0.running', true)
        );
    }

    public function test_pipeline_log_endpoint_returns_json(): void
    {
        $log = IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'records_processed' => 6160,
            'records_created' => 100,
            'records_updated' => 6060,
            'summary' => 'Processed: 6160 | Updated: 6060',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.pipeline.log', $log));

        $response->assertOk();
        $response->assertJsonFragment([
            'source' => 'scb',
            'status' => 'completed',
            'records_processed' => 6160,
        ]);
    }

    public function test_pipeline_run_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->actingAsAdmin()->post(route('admin.pipeline.run', 'scb'), [
            'command' => 'ingest',
        ]);

        $response->assertRedirect();
        Queue::assertPushed(RunIngestionCommand::class);
    }

    public function test_pipeline_run_rejects_unknown_command(): void
    {
        $response = $this->actingAsAdmin()->post(route('admin.pipeline.run', 'scb'), [
            'command' => 'nonexistent',
        ]);

        $response->assertStatus(400);
    }

    public function test_pipeline_run_all_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->actingAsAdmin()->post(route('admin.pipeline.run-all'));

        $response->assertRedirect();
        Queue::assertPushed(RunFullPipeline::class);
    }

    public function test_recent_logs_appear_on_pipeline_page(): void
    {
        IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(9),
            'records_processed' => 100,
            'summary' => 'Test run',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('recentLogs', 1)
            ->where('recentLogs.0.command', 'ingest:scb')
        );
    }

    public function test_source_detail_shows_logs_for_source(): void
    {
        IngestionLog::query()->create([
            'source' => 'scb',
            'command' => 'ingest:scb',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        IngestionLog::query()->create([
            'source' => 'bra',
            'command' => 'ingest:bra-crime',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.pipeline.show', 'scb'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('logs', 1)
        );
    }

    public function test_logs_ingestion_trait_creates_and_completes_log(): void
    {
        $command = new class extends \Illuminate\Console\Command
        {
            use LogsIngestion;

            protected $signature = 'test:logs-ingestion-trait';

            public function handle(): int
            {
                $this->startIngestionLog('test', 'test:command');
                $this->processed = 100;
                $this->created = 50;
                $this->updated = 50;
                $this->addStat('test_key', 'test_value');
                $this->addWarning('Test warning');
                $this->completeIngestionLog();

                return 0;
            }
        };

        $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $command->setLaravel($this->app);
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput);

        $log = IngestionLog::query()->where('source', 'test')->first();
        $this->assertNotNull($log);
        $this->assertEquals('completed', $log->status);
        $this->assertEquals(100, $log->records_processed);
        $this->assertEquals(50, $log->records_created);
        $this->assertEquals(50, $log->records_updated);
        $this->assertEquals(['test_key' => 'test_value'], $log->stats);
        $this->assertEquals(['Test warning'], $log->warnings);
        $this->assertNotNull($log->duration_seconds);
        $this->assertNotNull($log->memory_peak_mb);
    }

    public function test_logs_ingestion_trait_handles_failure(): void
    {
        $command = new class extends \Illuminate\Console\Command
        {
            use LogsIngestion;

            protected $signature = 'test:logs-ingestion-fail';

            public function handle(): int
            {
                $this->startIngestionLog('test', 'test:fail');
                $this->processed = 10;
                $this->failIngestionLog('Something went wrong');

                return 1;
            }
        };

        $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $command->setLaravel($this->app);
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput);

        $log = IngestionLog::query()->where('source', 'test')->first();
        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
        $this->assertEquals(10, $log->records_processed);
        $this->assertEquals('Something went wrong', $log->error_message);
    }

    public function test_pipeline_config_exists_with_required_keys(): void
    {
        $config = config('pipeline');
        $this->assertIsArray($config);
        $this->assertArrayHasKey('sources', $config);
        $this->assertArrayHasKey('pipeline_order', $config);

        foreach ($config['sources'] as $key => $source) {
            $this->assertArrayHasKey('name', $source, "Source {$key} missing name");
            $this->assertArrayHasKey('commands', $source, "Source {$key} missing commands");
            $this->assertArrayHasKey('expected_frequency', $source, "Source {$key} missing expected_frequency");
            $this->assertArrayHasKey('critical', $source, "Source {$key} missing critical");

            foreach ($source['commands'] as $cmdKey => $cmdConfig) {
                $this->assertArrayHasKey('command', $cmdConfig, "Source {$key} command {$cmdKey} missing 'command' key");
            }
        }
    }

    public function test_stats_are_accurate(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);

        $response = $this->actingAsAdmin()->get(route('admin.pipeline'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.total_ingestion_runs', 0)
            ->where('stats.runs_last_7_days', 0)
            ->where('stats.failed_last_7_days', 0)
        );
    }
}
