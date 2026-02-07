<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_returns_404_when_disabled(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user)->get('/email/verify');

        $response->assertNotFound();
    }

    public function test_email_verification_verify_returns_404_when_disabled(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user)->get('/email/verify/1/'.sha1($user->email));

        $response->assertNotFound();
    }
}
