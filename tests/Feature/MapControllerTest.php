<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_page_loads_successfully(): void
    {
        $response = $this->get(route('map'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('explore/map-page')
            ->has('initialCenter')
            ->has('initialZoom')
            ->where('initialCenter', [62, 15])
            ->where('initialZoom', 5)
        );
    }

    public function test_map_page_is_accessible_without_authentication(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}
