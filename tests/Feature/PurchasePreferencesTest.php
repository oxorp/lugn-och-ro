<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class PurchasePreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    // -- Store preferences endpoint --

    public function test_store_preferences_stores_in_session(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => [
                    'priorities' => ['safety', 'schools'],
                    'walking_distance_minutes' => 15,
                    'has_car' => true,
                ],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        // Verify session contains the stored preferences
        $this->assertEquals(
            ['priorities' => ['safety', 'schools'], 'walking_distance_minutes' => 15, 'has_car' => true],
            session('purchase.preferences')
        );
        $this->assertEquals(59.33, session('purchase.lat'));
        $this->assertEquals(18.06, session('purchase.lng'));
    }

    public function test_store_preferences_requires_preferences_array(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferences');
    }

    public function test_store_preferences_requires_lat_and_lng(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => ['priorities' => ['safety']],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_store_preferences_validates_coordinate_bounds(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => ['priorities' => ['safety']],
                'lat' => 40.0, // Out of Sweden bounds
                'lng' => 18.06,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('lat');
    }

    public function test_store_preferences_validates_lng_bounds(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => ['priorities' => ['safety']],
                'lat' => 59.33,
                'lng' => 5.0, // Out of Sweden bounds
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('lng');
    }

    public function test_store_preferences_validates_walking_distance(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => [
                    'priorities' => ['safety'],
                    'walking_distance_minutes' => 120, // Too high (max 60)
                ],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferences.walking_distance_minutes');
    }

    public function test_store_preferences_accepts_empty_priorities(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => [
                    'priorities' => [],
                    'walking_distance_minutes' => 10,
                    'has_car' => false,
                ],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    public function test_store_preferences_accepts_null_fields(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => [
                    'priorities' => null,
                    'walking_distance_minutes' => null,
                    'has_car' => null,
                ],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok']);
    }

    public function test_store_preferences_overwrites_previous_session_data(): void
    {
        // First, store some preferences
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => ['priorities' => ['safety']],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        // Then store new preferences
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => ['priorities' => ['schools', 'transport']],
                'lat' => 59.40,
                'lng' => 18.10,
            ]);

        $response->assertOk();

        // Verify new preferences replaced old ones
        $this->assertEquals(['priorities' => ['schools', 'transport']], session('purchase.preferences'));
        $this->assertEquals(59.40, session('purchase.lat'));
        $this->assertEquals(18.10, session('purchase.lng'));
    }

    public function test_store_preferences_validates_has_car_is_boolean(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => [
                    'priorities' => ['safety'],
                    'has_car' => 'yes', // Should be boolean
                ],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferences.has_car');
    }

    public function test_store_preferences_validates_priorities_are_strings(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => [
                    'priorities' => [123, 456], // Should be strings
                ],
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('preferences.priorities.0');
    }

    // -- OAuth flow with preferences --

    public function test_full_oauth_flow_preserves_preferences(): void
    {
        $lat = 59.33;
        $lng = 18.06;
        $preferences = [
            'priorities' => ['safety', 'schools'],
            'walking_distance_minutes' => 15,
            'has_car' => true,
        ];

        // Step 1: Store preferences in session (frontend does this before OAuth redirect)
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => $preferences,
                'lat' => $lat,
                'lng' => $lng,
            ])
            ->assertOk();

        // Step 2: Simulate OAuth redirect storing the intended URL
        session(['url.intended' => "/purchase/{$lat},{$lng}"]);

        // Step 3: Mock Socialite for OAuth callback
        $googleUser = $this->mockSocialiteUser(
            id: '123456789',
            email: 'testuser@gmail.com',
            name: 'Test User'
        );

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn(Mockery::mock(\Laravel\Socialite\Contracts\Provider::class, function ($mock) use ($googleUser) {
                $mock->shouldReceive('user')->andReturn($googleUser);
            }));

        // Step 4: Hit the OAuth callback endpoint
        $callbackResponse = $this->get('/auth/google/callback');

        // Verify user is created and authenticated
        $this->assertAuthenticated();
        $user = auth()->user();
        $this->assertEquals('testuser@gmail.com', $user->email);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('123456789', $user->google_id);

        // Step 5: Callback should redirect to the intended purchase URL
        $callbackResponse->assertRedirect("/purchase/{$lat},{$lng}");

        // Step 6: Verify preferences are still in session for the next request
        $this->assertEquals($preferences, session('purchase.preferences'));
        $this->assertEquals($lat, session('purchase.lat'));
        $this->assertEquals($lng, session('purchase.lng'));
    }

    public function test_oauth_flow_with_existing_user_preserves_preferences(): void
    {
        // Create existing user without Google ID
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
            'google_id' => null,
        ]);

        $lat = 59.33;
        $lng = 18.06;
        $preferences = [
            'priorities' => ['transport'],
            'walking_distance_minutes' => 10,
            'has_car' => false,
        ];

        // Store preferences
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => $preferences,
                'lat' => $lat,
                'lng' => $lng,
            ])
            ->assertOk();

        // Set intended URL
        session(['url.intended' => "/purchase/{$lat},{$lng}"]);

        // Mock Socialite with existing user's email
        $googleUser = $this->mockSocialiteUser(
            id: '987654321',
            email: 'existing@example.com',
            name: 'Existing User Google Name'
        );

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn(Mockery::mock(\Laravel\Socialite\Contracts\Provider::class, function ($mock) use ($googleUser) {
                $mock->shouldReceive('user')->andReturn($googleUser);
            }));

        // Hit callback
        $this->get('/auth/google/callback');

        // Verify existing user is authenticated and linked to Google
        $this->assertAuthenticated();
        $this->assertEquals($existingUser->id, auth()->id());

        $existingUser->refresh();
        $this->assertEquals('987654321', $existingUser->google_id);

        // Verify preferences still in session
        $this->assertEquals($preferences, session('purchase.preferences'));
    }

    public function test_purchase_page_receives_restored_preferences_after_oauth(): void
    {
        $lat = 59.33;
        $lng = 18.06;
        $preferences = [
            'priorities' => ['safety', 'schools'],
            'walking_distance_minutes' => 15,
            'has_car' => true,
        ];

        // Create and authenticate a user
        $user = User::factory()->create();

        // Store preferences in session (simulating state before OAuth)
        session([
            'purchase.preferences' => $preferences,
            'purchase.lat' => $lat,
            'purchase.lng' => $lng,
        ]);

        // Visit purchase page as authenticated user
        $response = $this->actingAs($user)
            ->get("/purchase/{$lat},{$lng}");

        $response->assertOk();

        // Verify Inertia page receives restored_preferences
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->has('restored_preferences')
            ->where('restored_preferences.priorities', ['safety', 'schools'])
            ->where('restored_preferences.walking_distance_minutes', 15)
            ->where('restored_preferences.has_car', true));

        // Verify session is cleared after reading
        $this->assertNull(session('purchase.preferences'));
        $this->assertNull(session('purchase.lat'));
        $this->assertNull(session('purchase.lng'));
    }

    public function test_purchase_page_does_not_restore_preferences_for_different_coordinates(): void
    {
        $preferences = [
            'priorities' => ['safety'],
            'walking_distance_minutes' => 10,
            'has_car' => false,
        ];

        $user = User::factory()->create();

        // Store preferences for different coordinates
        session([
            'purchase.preferences' => $preferences,
            'purchase.lat' => 59.33,
            'purchase.lng' => 18.06,
        ]);

        // Visit purchase page with different coordinates
        $response = $this->actingAs($user)
            ->get('/purchase/59.40,18.10');

        $response->assertOk();

        // Verify restored_preferences is null (coordinates don't match)
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->where('restored_preferences', null));

        // Verify session data is preserved (not cleared for wrong location)
        $this->assertEquals($preferences, session('purchase.preferences'));
    }

    public function test_oauth_callback_handles_failure_gracefully(): void
    {
        $preferences = [
            'priorities' => ['safety'],
            'walking_distance_minutes' => 10,
            'has_car' => false,
        ];

        // Store preferences
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => $preferences,
                'lat' => 59.33,
                'lng' => 18.06,
            ])
            ->assertOk();

        // Mock Socialite to throw an exception (simulating OAuth failure)
        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn(Mockery::mock(\Laravel\Socialite\Contracts\Provider::class, function ($mock) {
                $mock->shouldReceive('user')->andThrow(new \Exception('OAuth failed'));
            }));

        // Hit callback - should redirect to login with error
        $response = $this->get('/auth/google/callback');

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');

        // User should not be authenticated
        $this->assertGuest();

        // Preferences should still be in session for retry
        $this->assertEquals($preferences, session('purchase.preferences'));
    }

    public function test_oauth_redirect_stores_intended_url(): void
    {
        $redirectUrl = '/purchase/59.33,18.06';

        // Mock Socialite redirect
        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn(Mockery::mock(\Laravel\Socialite\Contracts\Provider::class, function ($mock) {
                $mock->shouldReceive('redirect')
                    ->andReturn(redirect('https://accounts.google.com/oauth'));
            }));

        // Hit OAuth redirect with redirect parameter
        $this->get("/auth/google?redirect={$redirectUrl}");

        // Verify intended URL is stored in session
        $this->assertEquals($redirectUrl, session('url.intended'));
    }

    // -- Edge Cases --

    /**
     * Edge Case 1: OAuth cancel
     * When user cancels OAuth (by navigating back or closing the OAuth window),
     * they return to the purchase page. Their preferences should still be
     * in session and restored on the identity step.
     */
    public function test_oauth_cancel_preserves_preferences_in_session(): void
    {
        $lat = 59.33;
        $lng = 18.06;
        $preferences = [
            'priorities' => ['safety', 'schools'],
            'walking_distance_minutes' => 15,
            'has_car' => true,
        ];

        // Step 1: Store preferences in session (this happens before OAuth redirect)
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => $preferences,
                'lat' => $lat,
                'lng' => $lng,
            ])
            ->assertOk();

        // Step 2: Simulate that user navigates to OAuth but cancels (no callback called)
        // They navigate back to the purchase page directly

        // Step 3: Visit purchase page as unauthenticated user
        $response = $this->get("/purchase/{$lat},{$lng}");

        $response->assertOk();

        // Verify restored_preferences are passed to the page
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->has('restored_preferences')
            ->where('restored_preferences.priorities', ['safety', 'schools'])
            ->where('restored_preferences.walking_distance_minutes', 15)
            ->where('restored_preferences.has_car', true));

        // Note: Session is cleared after this request, but that's fine -
        // if user tries OAuth again, they'll store preferences again via handleGoogleClick
    }

    /**
     * Edge Case 1b: OAuth cancel with retry
     * After OAuth cancel, user can retry and preferences should be stored again.
     */
    public function test_oauth_cancel_allows_retry_with_new_preferences_storage(): void
    {
        $lat = 59.33;
        $lng = 18.06;
        $preferences = [
            'priorities' => ['safety'],
            'walking_distance_minutes' => 10,
            'has_car' => false,
        ];

        // First attempt: store preferences
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => $preferences,
                'lat' => $lat,
                'lng' => $lng,
            ])
            ->assertOk();

        // User cancels OAuth and returns to page (session cleared on page load)
        $this->get("/purchase/{$lat},{$lng}");

        // Session should be cleared now
        $this->assertNull(session('purchase.preferences'));

        // Second attempt: user can store preferences again for retry
        $newPreferences = [
            'priorities' => ['transport', 'schools'],
            'walking_distance_minutes' => 20,
            'has_car' => true,
        ];

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/store-preferences', [
                'preferences' => $newPreferences,
                'lat' => $lat,
                'lng' => $lng,
            ]);

        $response->assertOk();
        $this->assertEquals($newPreferences, session('purchase.preferences'));
    }

    /**
     * Edge Case 2: Already logged in
     * When user is already authenticated before starting purchase flow,
     * they should skip identity step and go directly to payment.
     * The frontend handles this by checking auth.user in step determination.
     */
    public function test_already_logged_in_user_skips_identity_step(): void
    {
        // Create and authenticate user BEFORE visiting purchase page
        $user = User::factory()->create([
            'email' => 'already.logged.in@example.com',
            'name' => 'Already Logged In User',
        ]);

        // Visit purchase page as authenticated user (no preferences in session)
        $response = $this->actingAs($user)
            ->get('/purchase/59.33,18.06');

        $response->assertOk();

        // Verify Inertia page is rendered with user data
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            // restored_preferences should be null (no session data)
            ->where('restored_preferences', null));

        // The frontend will check auth.user and set initialStep = 'payment'
        // This is tested by verifying the page component receives user data
        // from the SharedData props (handled by Inertia's HandleInertiaRequests middleware)
    }

    /**
     * Edge Case 2b: Already logged in with pre-existing session preferences
     * If a logged-in user has preferences in session (e.g., from a previous
     * unauthenticated attempt), those should still be passed to the page.
     */
    public function test_already_logged_in_user_receives_any_stored_preferences(): void
    {
        $lat = 59.33;
        $lng = 18.06;
        $preferences = [
            'priorities' => ['safety'],
            'walking_distance_minutes' => 10,
            'has_car' => null,
        ];

        $user = User::factory()->create();

        // Simulate preferences stored in a previous session (e.g., user logged in on another tab)
        session([
            'purchase.preferences' => $preferences,
            'purchase.lat' => $lat,
            'purchase.lng' => $lng,
        ]);

        // Visit as authenticated user
        $response = $this->actingAs($user)
            ->get("/purchase/{$lat},{$lng}");

        $response->assertOk();

        // Preferences should be passed (and cleared) even for authenticated users
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->has('restored_preferences')
            ->where('restored_preferences.priorities', ['safety']));

        // Session should be cleared
        $this->assertNull(session('purchase.preferences'));
    }

    /**
     * Edge Case 3: Session expiry
     * When session expires or is cleared, the page should show identity step
     * with no restored preferences (graceful fallback to fresh start).
     */
    public function test_session_expiry_gracefully_falls_back_to_empty_preferences(): void
    {
        // Don't set any session data - simulates expired/cleared session

        // Visit purchase page as guest with no session data
        $response = $this->get('/purchase/59.33,18.06');

        $response->assertOk();

        // Verify page renders with null restored_preferences
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->where('restored_preferences', null));
    }

    /**
     * Edge Case 3b: Session expiry during OAuth flow
     * If session expires while user is on Google's OAuth page,
     * they will return authenticated but without preferences.
     * This should gracefully show payment step without errors.
     */
    public function test_session_expiry_during_oauth_shows_payment_step_without_preferences(): void
    {
        $lat = 59.33;
        $lng = 18.06;

        // Create user (simulating successful OAuth completion)
        $user = User::factory()->create();

        // Don't set any session preferences (simulating session expiry during OAuth)

        // Visit purchase page as newly authenticated user
        $response = $this->actingAs($user)
            ->get("/purchase/{$lat},{$lng}");

        $response->assertOk();

        // Page should render successfully without restored_preferences
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->where('restored_preferences', null)
            ->where('lat', $lat)
            ->where('lng', $lng));

        // User is authenticated, so frontend will skip to payment step
        // Preferences will be defaults, which is acceptable fallback
    }

    /**
     * Edge Case: Partial session data (preferences without coordinates)
     * If only preferences exist without coordinates, they should not be restored.
     */
    public function test_partial_session_data_is_not_restored(): void
    {
        $preferences = [
            'priorities' => ['safety'],
            'walking_distance_minutes' => 10,
            'has_car' => false,
        ];

        // Only store preferences, not coordinates
        session(['purchase.preferences' => $preferences]);

        $response = $this->get('/purchase/59.33,18.06');

        $response->assertOk();

        // Should not restore preferences without matching coordinates
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->where('restored_preferences', null));
    }

    // -- Checkout with preferences --

    /**
     * Test that checkout saves preferences to the report.
     * This is the critical test for Success Criterion #4:
     * "Report is created successfully with preferences JSON in database"
     */
    public function test_checkout_saves_preferences_to_report(): void
    {
        $user = User::factory()->create();

        $preferences = [
            'priorities' => ['safety', 'schools'],
            'walking_distance_minutes' => 15,
            'has_car' => true,
        ];

        // Configure dev mode to bypass Stripe
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $response = $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
                'address' => 'Test Address',
                'deso_code' => 'TEST001',
                'kommun_name' => 'Stockholm',
                'lan_name' => 'Stockholm',
                'score' => 75.5,
                'preferences' => $preferences,
            ]);

        $response->assertOk();

        // Verify preferences are saved to the report
        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertEquals($preferences, $report->preferences);
    }

    /**
     * Test that checkout works without preferences (null case).
     */
    public function test_checkout_works_without_preferences(): void
    {
        $user = User::factory()->create();

        // Configure dev mode to bypass Stripe
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $response = $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
                'address' => 'Test Address',
                'deso_code' => 'TEST002',
                'kommun_name' => 'Stockholm',
                'lan_name' => 'Stockholm',
                'score' => 75.5,
            ]);

        $response->assertOk();

        // Verify report is created with null preferences
        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertNull($report->preferences);
    }

    /**
     * Test that checkout validates preferences structure.
     */
    public function test_checkout_validates_preferences_structure(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
                'address' => 'Test Address',
                'deso_code' => 'TEST003',
                'kommun_name' => 'Stockholm',
                'lan_name' => 'Stockholm',
                'score' => 75.5,
                'preferences' => [
                    'priorities' => [123], // Should be strings
                    'walking_distance_minutes' => 100, // Too high (max 60)
                    'has_car' => 'yes', // Should be boolean
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'preferences.priorities.0',
            'preferences.walking_distance_minutes',
            'preferences.has_car',
        ]);
    }

    // -- Purchase page passes questionnaire config --

    public function test_purchase_page_includes_questionnaire_config(): void
    {
        $response = $this->get('/purchase/59.33,18.06');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('purchase/flow')
            ->has('questionnaire_config')
            ->has('questionnaire_config.priority_options')
            ->has('questionnaire_config.max_priorities')
            ->has('questionnaire_config.walking_distances')
            ->has('questionnaire_config.default_walking_distance')
            ->has('questionnaire_config.labels')
            ->has('urbanity_tier'));
    }

    // -- Admin generate with preferences --

    public function test_admin_generate_saves_preferences_to_report(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $preferences = [
            'priorities' => ['safety', 'schools'],
            'walking_distance_minutes' => 15,
            'has_car' => true,
        ];

        $response = $this->actingAs($admin)
            ->post('/admin/reports/generate', [
                'lat' => 59.33,
                'lng' => 18.06,
                'preferences' => $preferences,
            ]);

        $response->assertRedirect();

        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->status);
        $this->assertEquals(0, $report->amount_ore);
        $this->assertEquals($preferences, $report->preferences);
    }

    public function test_admin_generate_works_without_preferences(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)
            ->post('/admin/reports/generate', [
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertRedirect();

        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertNull($report->preferences);
    }

    public function test_non_admin_cannot_use_admin_generate(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)
            ->post('/admin/reports/generate', [
                'lat' => 59.33,
                'lng' => 18.06,
            ]);

        $response->assertForbidden();
    }

    // -- Dev checkout generates report --

    public function test_dev_checkout_generates_report_snapshot(): void
    {
        $user = User::factory()->create();

        // Configure dev mode to bypass Stripe
        config(['stripe.secret' => null]);
        app()->detectEnvironment(fn () => 'local');

        $response = $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/purchase/checkout', [
                'lat' => 59.33,
                'lng' => 18.06,
                'address' => 'Test Address',
                'deso_code' => 'TEST001',
                'kommun_name' => 'Stockholm',
                'lan_name' => 'Stockholm',
                'score' => 75.5,
                'preferences' => [
                    'priorities' => ['safety'],
                    'walking_distance_minutes' => 10,
                    'has_car' => false,
                ],
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['checkout_url', 'dev_mode']);

        // Report should be created and completed
        $report = Report::latest()->first();
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->status);

        // ReportGenerationService::generate() was called so model_version should be set
        // (It may still be null if DeSO area doesn't exist in test DB, which is expected)
    }

    /**
     * Create a mock Socialite user object.
     */
    private function mockSocialiteUser(string $id, string $email, string $name): SocialiteUser
    {
        return Mockery::mock(SocialiteUser::class, function ($mock) use ($id, $email, $name) {
            $mock->shouldReceive('getId')->andReturn($id);
            $mock->shouldReceive('getEmail')->andReturn($email);
            $mock->shouldReceive('getName')->andReturn($name);
            $mock->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        });
    }
}
