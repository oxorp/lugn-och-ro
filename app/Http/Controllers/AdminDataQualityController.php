<?php

namespace App\Http\Controllers;

use App\Models\IngestionLog;
use App\Models\ScoreVersion;
use App\Models\SentinelArea;
use App\Models\ValidationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminDataQualityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/data-quality', [
            'overallHealth' => $this->getOverallHealth(),
            'sourceHealth' => $this->getSourceHealth(),
            'latestVersion' => $this->getLatestVersion(),
            'recentValidations' => $this->getRecentValidations(),
            'sentinelResults' => $this->getSentinelResults(),
            'ingestionHistory' => $this->getIngestionHistory(),
            'scoreVersions' => $this->getScoreVersions(),
        ]);
    }

    public function publish(int $versionId): JsonResponse
    {
        $version = ScoreVersion::query()->findOrFail($versionId);

        if (! in_array($version->status, ['pending', 'validated'])) {
            return response()->json(['error' => 'Can only publish pending or validated versions'], 422);
        }

        ScoreVersion::query()
            ->where('year', $version->year)
            ->where('status', 'published')
            ->update(['status' => 'superseded']);

        $version->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return response()->json(['success' => true, 'version' => $version->id]);
    }

    public function rollback(int $versionId): JsonResponse
    {
        $target = ScoreVersion::query()->findOrFail($versionId);

        $current = ScoreVersion::query()
            ->where('year', $target->year)
            ->where('status', 'published')
            ->first();

        if ($current) {
            $current->update(['status' => 'rolled_back']);
        }

        $target->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return response()->json(['success' => true, 'version' => $target->id]);
    }

    /**
     * @return array{status: string, warnings: int, errors: int}
     */
    private function getOverallHealth(): array
    {
        $staleCount = DB::table('indicators')
            ->where('is_active', true)
            ->where('freshness_status', 'stale')
            ->count();

        $outdatedCount = DB::table('indicators')
            ->where('is_active', true)
            ->where('freshness_status', 'outdated')
            ->count();

        $recentErrors = ValidationResult::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $status = match (true) {
            $outdatedCount > 0 || $recentErrors > 5 => 'critical',
            $staleCount > 0 || $recentErrors > 0 => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'warnings' => $staleCount + $recentErrors,
            'errors' => $outdatedCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSourceHealth(): array
    {
        $sources = DB::table('indicators')
            ->where('is_active', true)
            ->select('source')
            ->distinct()
            ->pluck('source');

        return $sources->map(function (string $source) {
            $indicators = DB::table('indicators')
                ->where('source', $source)
                ->where('is_active', true)
                ->get(['slug', 'freshness_status', 'last_ingested_at', 'latest_data_date']);

            $worstStatus = 'current';
            foreach ($indicators as $ind) {
                if ($ind->freshness_status === 'outdated') {
                    $worstStatus = 'outdated';
                } elseif ($ind->freshness_status === 'stale' && $worstStatus !== 'outdated') {
                    $worstStatus = 'stale';
                } elseif ($ind->freshness_status === 'unknown' && $worstStatus === 'current') {
                    $worstStatus = 'unknown';
                }
            }

            $lastIngested = $indicators->max('last_ingested_at');

            return [
                'source' => $source,
                'status' => $worstStatus,
                'last_ingested' => $lastIngested,
                'indicator_count' => $indicators->count(),
            ];
        })->values()->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLatestVersion(): ?array
    {
        $version = ScoreVersion::query()
            ->latest('computed_at')
            ->first();

        if (! $version) {
            return null;
        }

        return [
            'id' => $version->id,
            'year' => $version->year,
            'status' => $version->status,
            'computed_at' => $version->computed_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'deso_count' => $version->deso_count,
            'mean_score' => (float) $version->mean_score,
            'stddev_score' => (float) $version->stddev_score,
            'notes' => $version->notes,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentValidations(): array
    {
        $logs = IngestionLog::query()
            ->whereHas('validationResults')
            ->latest('completed_at')
            ->limit(10)
            ->get();

        return $logs->map(function (IngestionLog $log) {
            $passed = $log->validationResults()->where('status', 'passed')->count();
            $failed = $log->validationResults()->where('status', 'failed')->count();
            $skipped = $log->validationResults()->where('status', 'skipped')->count();

            return [
                'id' => $log->id,
                'source' => $log->source,
                'command' => $log->command,
                'date' => $log->completed_at?->toIso8601String(),
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => $skipped,
                'status' => $log->status,
            ];
        })->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSentinelResults(): array
    {
        $sentinels = SentinelArea::query()->where('is_active', true)->get();
        $year = now()->year - 1;

        return $sentinels->map(function (SentinelArea $sentinel) use ($year) {
            $score = DB::table('composite_scores')
                ->where('deso_code', $sentinel->deso_code)
                ->where('year', $year)
                ->value('score');

            $scoreVal = $score !== null ? (float) $score : null;
            $inRange = $scoreVal !== null
                && $scoreVal >= (float) $sentinel->expected_score_min
                && $scoreVal <= (float) $sentinel->expected_score_max;

            return [
                'name' => $sentinel->name,
                'deso_code' => $sentinel->deso_code,
                'tier' => $sentinel->expected_tier,
                'score' => $scoreVal,
                'expected_min' => (float) $sentinel->expected_score_min,
                'expected_max' => (float) $sentinel->expected_score_max,
                'passed' => $scoreVal !== null && $inRange,
            ];
        })->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getIngestionHistory(): array
    {
        return IngestionLog::query()
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (IngestionLog $log) => [
                'id' => $log->id,
                'source' => $log->source,
                'command' => $log->command,
                'status' => $log->status,
                'records_processed' => $log->records_processed,
                'started_at' => $log->started_at?->toIso8601String(),
                'completed_at' => $log->completed_at?->toIso8601String(),
                'duration_seconds' => $log->started_at && $log->completed_at
                    ? $log->started_at->diffInSeconds($log->completed_at)
                    : null,
            ])->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getScoreVersions(): array
    {
        return ScoreVersion::query()
            ->latest('computed_at')
            ->limit(10)
            ->get()
            ->map(fn (ScoreVersion $v) => [
                'id' => $v->id,
                'year' => $v->year,
                'status' => $v->status,
                'deso_count' => $v->deso_count,
                'mean_score' => (float) $v->mean_score,
                'stddev_score' => (float) $v->stddev_score,
                'computed_at' => $v->computed_at?->toIso8601String(),
                'published_at' => $v->published_at?->toIso8601String(),
                'notes' => $v->notes,
            ])->toArray();
    }
}
