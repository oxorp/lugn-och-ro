<?php

namespace App\Services;

use App\DataTransferObjects\ValidationReport;
use App\DataTransferObjects\ValidationRuleResult;
use App\Models\IngestionLog;
use App\Models\ValidationResult;
use App\Models\ValidationRule;
use Illuminate\Support\Facades\DB;

class DataValidationService
{
    public function validateIngestion(IngestionLog $log, string $source, int $year): ValidationReport
    {
        $rules = ValidationRule::query()
            ->where(function ($q) use ($source) {
                $q->where('source', $source)->orWhereNull('source');
            })
            ->where('is_active', true)
            ->get();

        $results = [];
        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $source, $year);
            $results[] = $result;

            ValidationResult::query()->create([
                'ingestion_log_id' => $log->id,
                'validation_rule_id' => $rule->id,
                'status' => $result->status,
                'details' => $result->details,
                'affected_count' => $result->affectedCount,
                'message' => $result->message,
            ]);
        }

        return new ValidationReport($results);
    }

    private function evaluateRule(ValidationRule $rule, string $source, int $year): ValidationRuleResult
    {
        return match ($rule->rule_type) {
            'range' => $this->evaluateRange($rule, $year),
            'completeness' => $this->evaluateCompleteness($rule, $year),
            'change_rate' => $this->evaluateChangeRate($rule, $year),
            'distribution' => $this->evaluateDistribution($rule, $year),
            'global_min_count' => $this->evaluateGlobalMinCount($rule, $source, $year),
            'global_no_identical' => $this->evaluateGlobalNoIdentical($rule, $source, $year),
            'global_null_spike' => $this->evaluateGlobalNullSpike($rule, $source, $year),
            default => new ValidationRuleResult(
                ruleName: $rule->name,
                status: 'skipped',
                severity: $rule->severity,
                blocksScoring: $rule->blocks_scoring,
                message: "Unknown rule type: {$rule->rule_type}",
            ),
        };
    }

    private function evaluateRange(ValidationRule $rule, int $year): ValidationRuleResult
    {
        $params = $rule->parameters;
        $min = $params['min'] ?? null;
        $max = $params['max'] ?? null;

        $query = DB::table('indicator_values')
            ->where('indicator_id', $rule->indicator_id)
            ->where('year', $year)
            ->whereNotNull('raw_value');

        $violations = 0;
        $violationDetails = [];

        if ($min !== null) {
            $belowMin = (clone $query)->where('raw_value', '<', $min)->count();
            $violations += $belowMin;
            if ($belowMin > 0) {
                $violationDetails['below_min'] = $belowMin;
            }
        }

        if ($max !== null) {
            $aboveMax = (clone $query)->where('raw_value', '>', $max)->count();
            $violations += $aboveMax;
            if ($aboveMax > 0) {
                $violationDetails['above_max'] = $aboveMax;
            }
        }

        $total = $query->count();

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: $violations > 0 ? 'failed' : 'passed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: $violations,
            message: $violations > 0
                ? "{$violations} of {$total} values outside range [{$min}–{$max}]"
                : "All {$total} values within range [{$min}–{$max}]",
            details: $violationDetails ?: null,
        );
    }

    private function evaluateCompleteness(ValidationRule $rule, int $year): ValidationRuleResult
    {
        $params = $rule->parameters;
        $minCoveragePct = $params['min_coverage_pct'] ?? 80;

        $totalDesos = DB::table('deso_areas')->count();
        $covered = DB::table('indicator_values')
            ->where('indicator_id', $rule->indicator_id)
            ->where('year', $year)
            ->whereNotNull('raw_value')
            ->count();

        $coveragePct = $totalDesos > 0 ? round(($covered / $totalDesos) * 100, 1) : 0;

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: $coveragePct >= $minCoveragePct ? 'passed' : 'failed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: $totalDesos - $covered,
            message: "Coverage: {$coveragePct}% ({$covered}/{$totalDesos}), required: {$minCoveragePct}%",
            details: ['coverage_pct' => $coveragePct, 'covered' => $covered, 'total' => $totalDesos],
        );
    }

    private function evaluateChangeRate(ValidationRule $rule, int $year): ValidationRuleResult
    {
        $params = $rule->parameters;
        $maxChangePct = $params['max_change_pct'] ?? 50;
        $prevYear = $year - 1;

        $largeChanges = DB::select('
            SELECT COUNT(*) as cnt FROM (
                SELECT new_iv.deso_code,
                    ABS(new_iv.raw_value - old_iv.raw_value) / NULLIF(ABS(old_iv.raw_value), 0) * 100 AS change_pct
                FROM indicator_values new_iv
                JOIN indicator_values old_iv
                    ON old_iv.deso_code = new_iv.deso_code
                    AND old_iv.indicator_id = new_iv.indicator_id
                    AND old_iv.year = ?
                WHERE new_iv.indicator_id = ?
                    AND new_iv.year = ?
                    AND new_iv.raw_value IS NOT NULL
                    AND old_iv.raw_value IS NOT NULL
            ) sub
            WHERE change_pct > ?
        ', [$prevYear, $rule->indicator_id, $year, $maxChangePct]);

        $violationCount = $largeChanges[0]->cnt ?? 0;

        // Get total comparisons possible
        $totalComparisons = DB::table('indicator_values as new_iv')
            ->join('indicator_values as old_iv', function ($join) use ($prevYear) {
                $join->on('old_iv.deso_code', '=', 'new_iv.deso_code')
                    ->on('old_iv.indicator_id', '=', 'new_iv.indicator_id')
                    ->where('old_iv.year', '=', $prevYear);
            })
            ->where('new_iv.indicator_id', $rule->indicator_id)
            ->where('new_iv.year', $year)
            ->whereNotNull('new_iv.raw_value')
            ->whereNotNull('old_iv.raw_value')
            ->count();

        if ($totalComparisons === 0) {
            return new ValidationRuleResult(
                ruleName: $rule->name,
                status: 'skipped',
                severity: $rule->severity,
                blocksScoring: $rule->blocks_scoring,
                message: 'No previous year data available for comparison',
            );
        }

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: $violationCount > 0 ? 'failed' : 'passed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: $violationCount,
            message: "{$violationCount} of {$totalComparisons} DeSOs changed >{$maxChangePct}% year-over-year",
            details: ['large_changes' => $violationCount, 'total_comparisons' => $totalComparisons],
        );
    }

    private function evaluateDistribution(ValidationRule $rule, int $year): ValidationRuleResult
    {
        $params = $rule->parameters;

        $stats = DB::table('indicator_values')
            ->where('indicator_id', $rule->indicator_id)
            ->where('year', $year)
            ->whereNotNull('raw_value')
            ->selectRaw('AVG(raw_value) as mean_val, STDDEV(raw_value) as stddev_val')
            ->first();

        if (! $stats || $stats->mean_val === null) {
            return new ValidationRuleResult(
                ruleName: $rule->name,
                status: 'skipped',
                severity: $rule->severity,
                blocksScoring: $rule->blocks_scoring,
                message: 'No data available for distribution check',
            );
        }

        $mean = round((float) $stats->mean_val, 2);
        $stddev = round((float) $stats->stddev_val, 2);
        $issues = [];

        if (isset($params['expected_mean_min']) && $mean < $params['expected_mean_min']) {
            $issues[] = "Mean {$mean} below expected minimum {$params['expected_mean_min']}";
        }
        if (isset($params['expected_mean_max']) && $mean > $params['expected_mean_max']) {
            $issues[] = "Mean {$mean} above expected maximum {$params['expected_mean_max']}";
        }
        if (isset($params['expected_stddev_min']) && $stddev < $params['expected_stddev_min']) {
            $issues[] = "StdDev {$stddev} below expected minimum {$params['expected_stddev_min']}";
        }
        if (isset($params['expected_stddev_max']) && $stddev > $params['expected_stddev_max']) {
            $issues[] = "StdDev {$stddev} above expected maximum {$params['expected_stddev_max']}";
        }

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: empty($issues) ? 'passed' : 'failed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: count($issues),
            message: empty($issues)
                ? "Distribution OK: mean={$mean}, stddev={$stddev}"
                : implode('; ', $issues),
            details: ['mean' => $mean, 'stddev' => $stddev],
        );
    }

    private function evaluateGlobalMinCount(ValidationRule $rule, string $source, int $year): ValidationRuleResult
    {
        $params = $rule->parameters;
        $minCount = $params['min_deso_count'] ?? 1000;

        // Count distinct DeSOs with data for this source in this year
        $count = DB::table('indicator_values')
            ->join('indicators', 'indicators.id', '=', 'indicator_values.indicator_id')
            ->where('indicators.source', $source)
            ->where('indicator_values.year', $year)
            ->whereNotNull('indicator_values.raw_value')
            ->distinct('indicator_values.deso_code')
            ->count('indicator_values.deso_code');

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: $count >= $minCount ? 'passed' : 'failed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: $count,
            message: $count >= $minCount
                ? "Processed {$count} DeSOs (minimum: {$minCount})"
                : "Only {$count} DeSOs have data (minimum: {$minCount}) — possible partial import",
            details: ['deso_count' => $count, 'min_required' => $minCount],
        );
    }

    private function evaluateGlobalNoIdentical(ValidationRule $rule, string $source, int $year): ValidationRuleResult
    {
        // Check if any indicator for this source has 100% identical values
        $indicators = DB::table('indicators')
            ->where('source', $source)
            ->where('is_active', true)
            ->get(['id', 'slug']);

        $identicalSlugs = [];
        foreach ($indicators as $indicator) {
            $distinctCount = DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->where('year', $year)
                ->whereNotNull('raw_value')
                ->distinct('raw_value')
                ->count('raw_value');

            $totalCount = DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->where('year', $year)
                ->whereNotNull('raw_value')
                ->count();

            if ($totalCount > 10 && $distinctCount === 1) {
                $identicalSlugs[] = $indicator->slug;
            }
        }

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: empty($identicalSlugs) ? 'passed' : 'failed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: count($identicalSlugs),
            message: empty($identicalSlugs)
                ? 'No indicators have all-identical values'
                : 'Indicators with all-identical values (parsing error?): '.implode(', ', $identicalSlugs),
            details: $identicalSlugs ? ['identical_indicators' => $identicalSlugs] : null,
        );
    }

    private function evaluateGlobalNullSpike(ValidationRule $rule, string $source, int $year): ValidationRuleResult
    {
        $params = $rule->parameters;
        $maxNullIncreasePct = $params['max_null_increase_pct'] ?? 15;
        $prevYear = $year - 1;

        $indicators = DB::table('indicators')
            ->where('source', $source)
            ->where('is_active', true)
            ->get(['id', 'slug']);

        $spikedSlugs = [];
        $totalDesos = DB::table('deso_areas')->count();

        foreach ($indicators as $indicator) {
            $currentNulls = $totalDesos - DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->where('year', $year)
                ->whereNotNull('raw_value')
                ->count();

            $prevNulls = $totalDesos - DB::table('indicator_values')
                ->where('indicator_id', $indicator->id)
                ->where('year', $prevYear)
                ->whereNotNull('raw_value')
                ->count();

            $currentNullPct = $totalDesos > 0 ? ($currentNulls / $totalDesos) * 100 : 0;
            $prevNullPct = $totalDesos > 0 ? ($prevNulls / $totalDesos) * 100 : 0;

            if ($prevNullPct < 5 && $currentNullPct > 20 && ($currentNullPct - $prevNullPct) > $maxNullIncreasePct) {
                $spikedSlugs[] = "{$indicator->slug} ({$prevNullPct}% → {$currentNullPct}% null)";
            }
        }

        return new ValidationRuleResult(
            ruleName: $rule->name,
            status: empty($spikedSlugs) ? 'passed' : 'failed',
            severity: $rule->severity,
            blocksScoring: $rule->blocks_scoring,
            affectedCount: count($spikedSlugs),
            message: empty($spikedSlugs)
                ? 'No unexpected NULL spikes detected'
                : 'NULL spike detected (source schema change?): '.implode('; ', $spikedSlugs),
            details: $spikedSlugs ? ['spiked_indicators' => $spikedSlugs] : null,
        );
    }
}
