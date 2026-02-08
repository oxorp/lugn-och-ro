<?php

namespace App\DataTransferObjects;

use App\Models\Indicator;
use Illuminate\Support\Facades\Cache;

class ProximityResult
{
    public function __construct(
        public ProximityFactor $school,
        public ProximityFactor $greenSpace,
        public ProximityFactor $transit,
        public ProximityFactor $grocery,
        public ProximityFactor $negativePoi,
        public ProximityFactor $positivePoi,
    ) {}

    public function compositeScore(): float
    {
        $weights = $this->getWeights();

        $weighted = 0;
        $totalWeight = 0;

        foreach ($weights as $field => $weight) {
            $factor = $this->$field;
            if ($factor->score !== null) {
                $weighted += $factor->score * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? $weighted / $totalWeight : 50;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'composite' => round($this->compositeScore(), 1),
            'factors' => [
                $this->school->toArray(),
                $this->greenSpace->toArray(),
                $this->transit->toArray(),
                $this->grocery->toArray(),
                $this->negativePoi->toArray(),
                $this->positivePoi->toArray(),
            ],
        ];
    }

    /**
     * @return array<string, float>
     */
    private function getWeights(): array
    {
        $dbWeights = Cache::remember('proximity_indicator_weights', 300, function () {
            return Indicator::query()
                ->where('category', 'proximity')
                ->where('is_active', true)
                ->pluck('weight', 'slug')
                ->toArray();
        });

        return [
            'school' => (float) ($dbWeights['prox_school'] ?? 0.10),
            'greenSpace' => (float) ($dbWeights['prox_green_space'] ?? 0.04),
            'transit' => (float) ($dbWeights['prox_transit'] ?? 0.05),
            'grocery' => (float) ($dbWeights['prox_grocery'] ?? 0.03),
            'negativePoi' => (float) ($dbWeights['prox_negative_poi'] ?? 0.04),
            'positivePoi' => (float) ($dbWeights['prox_positive_poi'] ?? 0.04),
        ];
    }
}
