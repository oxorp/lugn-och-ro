<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_notification_returns_404_when_disabled(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->actingAs($user)->post('/email/verification-notification');

        $response->assertNotFound();
    }
}
