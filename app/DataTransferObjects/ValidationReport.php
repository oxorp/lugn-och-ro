<?php

namespace App\DataTransferObjects;

use Illuminate\Support\Collection;

class ValidationReport
{
    /** @var Collection<int, ValidationRuleResult> */
    public Collection $results;

    /**
     * @param  array<int, ValidationRuleResult>  $results
     */
    public function __construct(array $results)
    {
        $this->results = collect($results);
    }

    public function hasBlockingFailures(): bool
    {
        return $this->results->contains(
            fn (ValidationRuleResult $r) => $r->status === 'failed' && $r->blocksScoring
        );
    }

    public function hasWarnings(): bool
    {
        return $this->results->contains(
            fn (ValidationRuleResult $r) => $r->status === 'failed' && ! $r->blocksScoring
        );
    }

    public function failedCount(): int
    {
        return $this->results->where('status', 'failed')->count();
    }

    public function passedCount(): int
    {
        return $this->results->where('status', 'passed')->count();
    }

    public function warningCount(): int
    {
        return $this->results->filter(
            fn (ValidationRuleResult $r) => $r->status === 'failed' && $r->severity === 'warning'
        )->count();
    }

    public function summary(): string
    {
        $lines = [];
        foreach ($this->results as $result) {
            if ($result->status === 'failed') {
                $prefix = $result->blocksScoring ? '[BLOCKING]' : '[WARNING]';
                $lines[] = "{$prefix} {$result->ruleName}: {$result->message}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{passed: int, failed: int, warnings: int, blocking: bool, details: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passedCount(),
            'failed' => $this->failedCount(),
            'warnings' => $this->warningCount(),
            'blocking' => $this->hasBlockingFailures(),
            'details' => $this->results->map(fn (ValidationRuleResult $r) => [
                'rule' => $r->ruleName,
                'status' => $r->status,
                'message' => $r->message,
                'affected_count' => $r->affectedCount,
            ])->toArray(),
        ];
    }
}
