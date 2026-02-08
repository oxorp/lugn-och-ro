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
}
