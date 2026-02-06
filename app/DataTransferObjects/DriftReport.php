<?php

namespace App\DataTransferObjects;

class DriftReport
{
    /**
     * @param  array<int, object>  $areasWithLargeDrift
     */
    public function __construct(
        public int $totalAreas,
        public float $meanDrift,
        public float $maxDrift,
        public float $meanScoreNew,
        public float $meanScoreOld,
        public float $stddevNew,
        public float $stddevOld,
        public array $areasWithLargeDrift = [],
    ) {}

    public function hasSystemicShift(float $threshold = 5.0): bool
    {
        return abs($this->meanScoreNew - $this->meanScoreOld) > $threshold;
    }

    public function hasLargeDriftCount(int $threshold = 100, float $driftThreshold = 15.0): bool
    {
        $count = count(array_filter(
            $this->areasWithLargeDrift,
            fn ($a) => abs($a->drift) > $driftThreshold
        ));

        return $count > $threshold;
    }

    public function hasStddevShift(float $pctThreshold = 20.0): bool
    {
        if ($this->stddevOld == 0) {
            return false;
        }

        $pctChange = abs($this->stddevNew - $this->stddevOld) / $this->stddevOld * 100;

        return $pctChange > $pctThreshold;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_areas' => $this->totalAreas,
            'mean_drift' => round($this->meanDrift, 2),
            'max_drift' => round($this->maxDrift, 2),
            'mean_score_new' => round($this->meanScoreNew, 2),
            'mean_score_old' => round($this->meanScoreOld, 2),
            'stddev_new' => round($this->stddevNew, 2),
            'stddev_old' => round($this->stddevOld, 2),
            'large_drift_count' => count($this->areasWithLargeDrift),
            'systemic_shift' => $this->hasSystemicShift(),
            'stddev_shift' => $this->hasStddevShift(),
        ];
    }
}
