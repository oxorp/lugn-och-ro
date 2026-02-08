<?php

namespace App\DataTransferObjects;

class ProximityFactor
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $slug,
        public ?int $score,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'score' => $this->score,
            'details' => $this->details,
        ];
    }
}
