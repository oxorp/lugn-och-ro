<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForMaklarePageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_swedish_for_makare_route_renders_successfully(): void
    {
        $response = $this->get('/for-makare');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('for-makare')
        );
    }

    public function test_english_for_agents_route_renders_successfully(): void
    {
        $response = $this->get('/en/for-agents');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('for-makare')
        );
    }

    public function test_swedish_route_sets_locale_to_sv(): void
    {
        $this->get('/for-makare');

        $this->assertEquals('sv', app()->getLocale());
    }

    public function test_english_route_sets_locale_to_en(): void
    {
        $this->get('/en/for-agents');

        $this->assertEquals('en', app()->getLocale());
    }

    public function test_for_makare_route_has_correct_name(): void
    {
        $this->assertEquals(
            url('/for-makare'),
            route('for-makare')
        );
    }

    public function test_english_for_makare_route_has_correct_name(): void
    {
        $this->assertEquals(
            url('/en/for-agents'),
            route('en.for-makare')
        );
    }
}
