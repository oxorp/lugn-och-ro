<?php

namespace Tests\Feature;

use App\Listeners\ClaimGuestReports;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_reports_claimed_on_login(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com']);

        $guestReport = Report::factory()->completed()->forGuest('test@example.com')->create();
        $otherReport = Report::factory()->completed()->forGuest('other@example.com')->create();

        $listener = new ClaimGuestReports;
        $listener->handle(new Login('web', $user, false));

        $this->assertDatabaseHas('reports', [
            'id' => $guestReport->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('reports', [
            'id' => $otherReport->id,
            'user_id' => null,
        ]);
    }

    public function test_claim_does_not_affect_already_claimed_reports(): void
    {
        $user1 = User::factory()->create(['email' => 'shared@example.com']);
        $user2 = User::factory()->create();

        $claimedReport = Report::factory()->completed()->create([
            'user_id' => $user2->id,
            'guest_email' => 'shared@example.com',
        ]);

        $listener = new ClaimGuestReports;
        $listener->handle(new Login('web', $user1, false));

        // Report already had user_id, so it shouldn't change
        $this->assertDatabaseHas('reports', [
            'id' => $claimedReport->id,
            'user_id' => $user2->id,
        ]);
    }

    public function test_my_reports_shows_reports_for_logged_in_user(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->completed()->create(['user_id' => $user->id]);
        Report::factory()->completed()->create(); // Another user's report

        $response = $this->actingAs($user)->get('/my-reports');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/my-reports')
            ->has('reports', 1)
        );
    }

    public function test_my_reports_shows_request_access_for_guests(): void
    {
        $response = $this->get('/my-reports');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('reports/request-access')
        );
    }

    public function test_report_model_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $report->user);
        $this->assertEquals($user->id, $report->user->id);
    }

    public function test_report_is_paid_helper(): void
    {
        $completed = Report::factory()->completed()->create();
        $pending = Report::factory()->create(['status' => 'pending']);
        $expired = Report::factory()->expired()->create();

        $this->assertTrue($completed->isPaid());
        $this->assertFalse($pending->isPaid());
        $this->assertFalse($expired->isPaid());
    }
}
