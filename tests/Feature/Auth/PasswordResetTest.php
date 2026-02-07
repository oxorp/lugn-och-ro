<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_screen_returns_404_when_disabled(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertNotFound();
    }

    public function test_reset_password_post_returns_404_when_disabled(): void
    {
        $response = $this->post('/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertNotFound();
    }

    public function test_reset_password_update_returns_404_when_disabled(): void
    {
        $response = $this->post('/reset-password', [
            'token' => 'fake-token',
            'email' => 'test@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ]);

        $response->assertNotFound();
    }
}
