<?php

namespace App\Services;

use App\Enums\DataTier;
use App\Models\User;
use Illuminate\Support\Collection;

class DataTieringService
{
    public function resolveUserTier(?User $user, ?string $desoCode = null): DataTier
    {
        if (! $user) {
            return DataTier::Public;
        }

        if ($user->isAdmin()) {
            return DataTier::Admin;
        }

        if ($user->hasApiAccess()) {
            return DataTier::Enterprise;
        }

        if ($user->hasActiveSubscription()) {
            return DataTier::Subscriber;
        }

        if ($desoCode && $user->hasUnlocked($desoCode)) {
            return DataTier::Unlocked;
        }

        return DataTier::FreeAccount;
    }

    /**
     * Resolve the effective tier, respecting admin "View As" session override.
     *
     * Admins can simulate viewing the app as a lower tier via session('viewAs').
     * This only affects data responses — route access remains unchanged.
     */
    public function resolveEffectiveTier(?User $user, ?string $desoCode = null): DataTier
    {
        $baseTier = $this->resolveUserTier($user, $desoCode);

        if ($user?->isAdmin() && session()->has('viewAs')) {
            $override = DataTier::tryFrom((int) session('viewAs'));
            if ($override !== null && $override->value < DataTier::Admin->value) {
                return $override;
            }
        }

        return $baseTier;
    }

    /**
     * Get the current "View As" override tier, or null if none active.
     */
    public function getViewAsOverride(?User $user): ?DataTier
    {
        if (! $user?->isAdmin() || ! session()->has('viewAs')) {
            return null;
        }

        return DataTier::tryFrom((int) session('viewAs'));
    }

    /**
     * Transform indicator data based on tier.
     *
     * @param  Collection<int, array<string, mixed>>  $indicators
     * @return Collection<int, array<string, mixed>>
     */
    public function transformIndicators(Collection $indicators, DataTier $tier): Collection
    {
        return $indicators->map(fn (array $ind) => $this->transformIndicator($ind, $tier));
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    private function transformIndicator(array $indicator, DataTier $tier): array
    {
        return match ($tier) {
            DataTier::Public => $this->forPublic($indicator),
            DataTier::FreeAccount => $this->forFreeAccount($indicator),
            DataTier::Unlocked => $this->forUnlocked($indicator),
            DataTier::Subscriber => $this->forSubscriber($indicator),
            DataTier::Enterprise => $this->forSubscriber($indicator),
            DataTier::Admin => $this->forAdmin($indicator),
        };
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    private function forPublic(array $indicator): array
    {
        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'] ?? null,
            'locked' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    private function forFreeAccount(array $indicator): array
    {
        $percentile = isset($indicator['normalized_value']) ? (float) $indicator['normalized_value'] * 100 : null;

        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'] ?? null,
            'band' => $this->percentileToBand($percentile),
            'bar_width' => $this->percentileToBarWidth($percentile),
            'direction' => $indicator['direction'],
            'trend_direction' => $this->trendToDirection($indicator['trend'] ?? null),
            'locked' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    private function forUnlocked(array $indicator): array
    {
        $percentile = isset($indicator['normalized_value']) ? (float) $indicator['normalized_value'] * 100 : null;

        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'] ?? null,
            'percentile_band' => $this->percentileToWideBand($percentile),
            'bar_width' => $this->percentileToBarWidth($percentile),
            'direction' => $indicator['direction'],
            'raw_value_approx' => $this->roundRawValue(
                isset($indicator['raw_value']) ? (float) $indicator['raw_value'] : null,
                $indicator['unit'] ?? null,
            ),
            'trend_direction' => $this->trendToDirection($indicator['trend'] ?? null),
            'trend_band' => $this->trendToBand($indicator['trend'] ?? null),
            'description_short' => $indicator['description_short'] ?? null,
            'source_name' => $indicator['source_name'] ?? null,
            'data_vintage' => $indicator['data_vintage'] ?? null,
            'locked' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    private function forSubscriber(array $indicator): array
    {
        $percentile = isset($indicator['normalized_value']) ? (float) $indicator['normalized_value'] * 100 : null;

        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'] ?? null,
            'percentile' => $percentile !== null ? round($percentile, 1) : null,
            'raw_value' => isset($indicator['raw_value']) ? round((float) $indicator['raw_value'], 4) : null,
            'normalized_value' => isset($indicator['normalized_value']) ? round((float) $indicator['normalized_value'], 6) : null,
            'unit' => $indicator['unit'] ?? null,
            'direction' => $indicator['direction'],
            'normalization_scope' => $indicator['normalization_scope'] ?? 'national',
            'trend' => $indicator['trend'] ?? null,
            'history' => $indicator['history'] ?? [],
            'bar_width' => $percentile !== null ? $percentile / 100 : 0,
            'description_short' => $indicator['description_short'] ?? null,
            'description_long' => $indicator['description_long'] ?? null,
            'methodology_note' => $indicator['methodology_note'] ?? null,
            'national_context' => $indicator['national_context'] ?? null,
            'source_name' => $indicator['source_name'] ?? null,
            'source_url' => $indicator['source_url'] ?? null,
            'data_vintage' => $indicator['data_vintage'] ?? null,
            'data_last_ingested_at' => $indicator['data_last_ingested_at'] ?? null,
            'locked' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    private function forAdmin(array $indicator): array
    {
        return [
            ...$this->forSubscriber($indicator),
            'weight' => $indicator['weight'] ?? null,
            'weighted_contribution' => $indicator['weighted_contribution'] ?? null,
            'rank' => $indicator['rank'] ?? null,
            'rank_total' => $indicator['rank_total'] ?? null,
            'normalization_method' => $indicator['normalization_method'] ?? null,
            'coverage_count' => $indicator['coverage_count'] ?? null,
            'coverage_total' => $indicator['coverage_total'] ?? null,
            'source_api_path' => $indicator['source_api_path'] ?? null,
            'source_field_code' => $indicator['source_field_code'] ?? null,
            'data_quality_notes' => $indicator['data_quality_notes'] ?? null,
            'admin_notes' => $indicator['admin_notes'] ?? null,
        ];
    }

    /**
     * 5-band system for free accounts.
     */
    public function percentileToBand(?float $percentile): ?string
    {
        if ($percentile === null) {
            return null;
        }

        return match (true) {
            $percentile >= 80 => 'very_high',
            $percentile >= 60 => 'high',
            $percentile >= 40 => 'average',
            $percentile >= 20 => 'low',
            default => 'very_low',
        };
    }

    /**
     * 8-band system for unlocked areas.
     */
    public function percentileToWideBand(?float $percentile): ?string
    {
        if ($percentile === null) {
            return null;
        }

        return match (true) {
            $percentile >= 95 => 'top_5',
            $percentile >= 90 => 'top_10',
            $percentile >= 75 => 'top_25',
            $percentile >= 50 => 'upper_half',
            $percentile >= 25 => 'lower_half',
            $percentile >= 10 => 'bottom_25',
            $percentile >= 5 => 'bottom_10',
            default => 'bottom_5',
        };
    }

    /**
     * Quantize bar width to nearest 5% to prevent reverse-engineering exact percentiles.
     */
    public function percentileToBarWidth(?float $percentile): float
    {
        if ($percentile === null) {
            return 0;
        }

        return round($percentile / 5) * 5 / 100;
    }

    /**
     * Round raw values to prevent exact data extraction.
     */
    public function roundRawValue(?float $value, ?string $unit): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($unit) {
            'SEK' => '~'.number_format(round($value / 5000) * 5000, 0, '.', ',').' kr',
            '%', 'percent' => '~'.round($value, 0).'%',
            '/1000', '/100k' => '~'.round($value, 0),
            'points' => '~'.(round($value / 5) * 5),
            default => '~'.round($value, 0),
        };
    }

    /**
     * Extract trend direction only (no magnitude).
     *
     * @param  array<string, mixed>|null  $trend
     */
    public function trendToDirection(?array $trend): ?string
    {
        if (! $trend || ($trend['direction'] ?? null) === 'insufficient') {
            return null;
        }

        return $trend['direction'] ?? null;
    }

    /**
     * Convert trend to a magnitude band label.
     *
     * @param  array<string, mixed>|null  $trend
     */
    public function trendToBand(?array $trend): ?string
    {
        if (! $trend || ($trend['direction'] ?? null) === 'insufficient') {
            return null;
        }

        $pctChange = abs($trend['percent_change'] ?? 0);

        return match (true) {
            $pctChange >= 10 => 'large',
            $pctChange >= 5 => 'moderate',
            $pctChange >= 2 => 'small',
            default => 'minimal',
        };
    }
}
