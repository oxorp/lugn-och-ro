<?php

namespace Tests\Feature;

use Tests\TestCase;

class MethodologyPageTest extends TestCase
{
    public function test_methodology_page_returns_200(): void
    {
        $response = $this->get('/methodology');

        $response->assertStatus(200);
    }

    public function test_methodology_page_renders_inertia_component(): void
    {
        $response = $this->get('/methodology');

        $response->assertInertia(fn ($page) => $page->component('methodology'));
    }

    public function test_methodology_route_has_correct_name(): void
    {
        $this->assertEquals('/methodology', route('methodology', [], false));
    }

    public function test_methodology_page_has_no_backend_data(): void
    {
        $response = $this->get('/methodology');

        $response->assertInertia(fn ($page) => $page
            ->component('methodology')
            ->where('errors', [])
        );
    }
}
