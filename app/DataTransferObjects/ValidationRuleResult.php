<?php

namespace App\DataTransferObjects;

class ValidationRuleResult
{
    public function __construct(
        public string $ruleName,
        public string $status,
        public string $severity,
        public bool $blocksScoring,
        public int $affectedCount = 0,
        public string $message = '',
        public ?array $details = null,
    ) {}
}
