<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'user_id' => User::factory(),
            'guest_email' => null,
            'lat' => fake()->latitude(55, 69),
            'lng' => fake()->longitude(11, 25),
            'address' => fake()->streetAddress(),
            'kommun_name' => fake()->city(),
            'lan_name' => fake()->city().'s län',
            'deso_code' => fake()->numerify('####C####'),
            'score' => fake()->randomFloat(2, 20, 85),
            'score_label' => fake()->randomElement(['Stabilt / Positivt', 'Blandat', 'Förhöjd risk']),
            'stripe_session_id' => 'cs_test_'.Str::random(24),
            'stripe_payment_intent_id' => null,
            'amount_ore' => 7900,
            'currency' => 'sek',
            'status' => 'pending',
            'view_count' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'stripe_payment_intent_id' => 'pi_test_'.Str::random(24),
        ]);
    }

    public function withSnapshot(): static
    {
        $score = fake()->randomFloat(2, 30, 85);

        return $this->state(fn () => [
            'default_score' => $score,
            'personalized_score' => $score,
            'trend_1y' => fake()->randomFloat(2, -5, 8),
            'indicator_count' => 19,
            'year' => 2024,
            'model_version' => 'v1.0',
            'area_indicators' => $this->fakeIndicators(),
            'category_verdicts' => $this->fakeVerdicts(),
            'score_history' => $this->fakeScoreHistory($score),
            'deso_meta' => [
                'deso_code' => fake()->numerify('####C####'),
                'deso_name' => 'Testområde',
                'kommun_name' => fake()->city(),
                'lan_name' => fake()->city().'s län',
                'area_km2' => fake()->randomFloat(2, 0.3, 15),
                'population' => fake()->numberBetween(500, 5000),
                'urbanity_tier' => fake()->randomElement(['urban', 'semi_urban', 'rural']),
            ],
            'schools' => [
                [
                    'name' => 'Testskolan',
                    'type' => 'Grundskola',
                    'operator_type' => 'Kommun',
                    'distance_m' => 350,
                    'merit_value' => 232.5,
                    'goal_achievement' => 82.0,
                    'teacher_certification' => 78.0,
                    'student_count' => '320',
                    'lat' => 59.32,
                    'lng' => 18.07,
                ],
            ],
            'proximity_factors' => [
                'composite' => 65.0,
                'factors' => [],
            ],
            'national_references' => [],
            'map_snapshot' => null,
            'outlook' => [
                'outlook' => 'positive',
                'outlook_label' => 'Positiv',
                'total_change' => 5.2,
                'years_span' => 6,
                'improving_count' => 2,
                'declining_count' => 0,
                'total_categories' => 4,
                'text_sv' => 'Utvecklingen pekar i positiv riktning.',
                'disclaimer' => 'Statistisk uppskattning.',
            ],
            'top_positive' => [
                ['category' => 'economy', 'slug' => 'median_income', 'text_sv' => 'Hög medianinkomst.', 'percentile' => 82],
            ],
            'top_negative' => [
                ['category' => 'safety', 'slug' => 'crime_total_rate', 'text_sv' => 'Brottslighet över genomsnittet.', 'percentile' => 28],
            ],
            'priorities' => [],
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
        ]);
    }

    public function forGuest(?string $email = null): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'guest_email' => $email ?? fake()->email(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fakeIndicators(): array
    {
        return [
            [
                'slug' => 'median_income',
                'name' => 'Medianinkomst',
                'category' => 'economy',
                'source' => 'scb',
                'unit' => 'SEK',
                'direction' => 'positive',
                'raw_value' => 287000,
                'formatted_value' => "287\u{00A0}000 kr",
                'normalized_value' => 0.78,
                'percentile' => 78,
                'description' => 'Medelinkomst efter skatt.',
                'trend' => [
                    'years' => [2019, 2020, 2021, 2022, 2023, 2024],
                    'percentiles' => [68, 71, 73, 74, 75, 78],
                    'raw_values' => [241000, 251000, 259000, 268000, 275000, 287000],
                    'change_1y' => 3,
                    'change_3y' => 5,
                    'change_5y' => 10,
                ],
            ],
            [
                'slug' => 'employment_rate',
                'name' => 'Sysselsättningsgrad',
                'category' => 'economy',
                'source' => 'scb',
                'unit' => 'percent',
                'direction' => 'positive',
                'raw_value' => 72.3,
                'formatted_value' => "72,3\u{00A0}%",
                'normalized_value' => 0.61,
                'percentile' => 61,
                'description' => 'Andel förvärvsarbetande.',
                'trend' => [
                    'years' => [2019, 2020, 2021, 2022, 2023, 2024],
                    'percentiles' => [58, 59, 60, 60, 61, 61],
                    'raw_values' => [70.1, 70.5, 71.0, 71.3, 71.8, 72.3],
                    'change_1y' => 0,
                    'change_3y' => 1,
                    'change_5y' => 3,
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function fakeVerdicts(): array
    {
        return [
            'safety' => [
                'label' => 'Trygghet & brottslighet',
                'score' => 62,
                'grade' => 'B',
                'color' => '#6abf4b',
                'verdict_sv' => 'Tryggheten i området ligger något över riksgenomsnittet med stabil trend.',
                'trend_direction' => 'stable',
                'indicator_count' => 4,
            ],
            'economy' => [
                'label' => 'Ekonomi & arbetsmarknad',
                'score' => 72,
                'grade' => 'B',
                'color' => '#6abf4b',
                'verdict_sv' => 'Den ekonomiska situationen ligger något över riksgenomsnittet.',
                'trend_direction' => 'improving',
                'indicator_count' => 5,
            ],
            'education' => [
                'label' => 'Utbildning & skolor',
                'score' => 55,
                'grade' => 'C',
                'color' => '#f0c040',
                'verdict_sv' => 'Utbildningsnivån ligger nära riksgenomsnittet.',
                'trend_direction' => 'stable',
                'indicator_count' => 4,
            ],
            'environment' => [
                'label' => 'Miljö & service',
                'score' => 68,
                'grade' => 'B',
                'color' => '#6abf4b',
                'verdict_sv' => 'Tillgången till service ligger något över riksgenomsnittet.',
                'trend_direction' => 'stable',
                'indicator_count' => 3,
            ],
        ];
    }

    /**
     * @return array<int, array{year: int, score: float}>
     */
    private function fakeScoreHistory(float $currentScore): array
    {
        $history = [];
        for ($y = 2019; $y <= 2024; $y++) {
            $history[] = [
                'year' => $y,
                'score' => round($currentScore - (2024 - $y) * fake()->randomFloat(1, 0.5, 2.0), 1),
            ];
        }

        return $history;
    }
}
