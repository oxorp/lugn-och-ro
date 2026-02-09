<?php

namespace App\Services;

use App\Models\DeSoCrosswalk;
use Illuminate\Support\Facades\Log;

class CrosswalkService
{
    /**
     * Map a historical value from an old DeSO code to new DeSO code(s).
     *
     * For rate/percentage indicators (income, employment rate, education %):
     *   - 1:1 mappings: use the value directly
     *   - Split mappings: assign the same rate to all child areas
     *
     * For count indicators (population):
     *   - 1:1 mappings: use the value directly
     *   - Split mappings: distribute proportionally by overlap area
     *
     * @return array<string, float> [new_code => value, ...]
     */
    public function mapOldToNew(string $oldCode, float $rawValue, string $unit): array
    {
        $mappings = DeSoCrosswalk::where('old_code', $oldCode)->get();

        if ($mappings->isEmpty()) {
            Log::warning("No crosswalk mapping for old DeSO: {$oldCode}");

            return [];
        }

        $results = [];
        foreach ($mappings as $mapping) {
            if ($this->isRateUnit($unit)) {
                $results[$mapping->new_code] = $rawValue;
            } else {
                $results[$mapping->new_code] = $rawValue * $mapping->overlap_fraction;
            }
        }

        return $results;
    }

    /**
     * Bulk map: given [old_code => value], return [new_code => value].
     *
     * For split areas with rates, all children get the parent's rate.
     * For split areas with counts, children get proportional shares.
     * For merged areas with rates, uses area-weighted average.
     * For merged areas with counts, sums contributions.
     *
     * @param  array<string, float>  $oldValues  [old_code => raw_value, ...]
     * @return array<string, float> [new_code => value, ...]
     */
    public function bulkMapOldToNew(array $oldValues, string $unit): array
    {
        $crosswalk = DeSoCrosswalk::whereIn('old_code', array_keys($oldValues))
            ->get()
            ->groupBy('old_code');

        $isRate = $this->isRateUnit($unit);
        $newValues = [];

        // For rate merge accumulation, track total reverse_fraction per new_code
        $reverseWeights = [];

        foreach ($oldValues as $oldCode => $rawValue) {
            $mappings = $crosswalk->get($oldCode, collect());

            if ($mappings->isEmpty()) {
                continue;
            }

            foreach ($mappings as $mapping) {
                $newCode = $mapping->new_code;

                if ($isRate) {
                    // For rates: use reverse_fraction as area weight for merges
                    if (isset($newValues[$newCode])) {
                        $newValues[$newCode] += $rawValue * $mapping->reverse_fraction;
                        $reverseWeights[$newCode] += $mapping->reverse_fraction;
                    } else {
                        $newValues[$newCode] = $rawValue * $mapping->reverse_fraction;
                        $reverseWeights[$newCode] = $mapping->reverse_fraction;
                    }
                } else {
                    // For counts: distribute by overlap_fraction and sum
                    $mappedValue = $rawValue * $mapping->overlap_fraction;
                    $newValues[$newCode] = ($newValues[$newCode] ?? 0) + $mappedValue;
                }
            }
        }

        // For rates: normalize by total reverse weight so 1:1 mappings stay exact
        if ($isRate) {
            foreach ($newValues as $newCode => $weightedSum) {
                $totalWeight = $reverseWeights[$newCode] ?? 1.0;
                if ($totalWeight > 0) {
                    $newValues[$newCode] = $weightedSum / $totalWeight;
                }
            }
        }

        return $newValues;
    }

    private function isRateUnit(string $unit): bool
    {
        return in_array($unit, ['percent', 'SEK', 'per_1000', 'per_100000', 'rate', 'ratio']);
    }
}
