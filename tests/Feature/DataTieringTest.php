<?php

namespace Tests\Feature;

use App\Enums\DataTier;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\DataTieringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataTieringTest extends TestCase
{
    use RefreshDatabase;

    private DataTieringService $tiering;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiering = app(DataTieringService::class);
    }

    // === Tier Resolution ===

    public function test_guest_resolves_to_public_tier(): void
    {
        $tier = $this->tiering->resolveUserTier(null);

        $this->assertEquals(DataTier::Public, $tier);
        $this->assertEquals(0, $tier->value);
    }

    public function test_authenticated_user_resolves_to_free_account(): void
    {
        $user = User::factory()->create();

        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::FreeAccount, $tier);
    }

    public function test_admin_resolves_to_admin_tier(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::Admin, $tier);
        $this->assertEquals(99, $tier->value);
    }

    public function test_subscriber_resolves_to_subscriber_tier(): void
    {
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::Subscriber, $tier);
    }

    public function test_expired_subscription_resolves_to_free_account(): void
    {
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now()->subMonths(2),
            'current_period_end' => now()->subMonth(),
        ]);

        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::FreeAccount, $tier);
    }

    public function test_cancelled_subscription_resolves_to_free_account(): void
    {
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'cancelled',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'cancelled_at' => now(),
        ]);

        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::FreeAccount, $tier);
    }

    public function test_deso_unlock_resolves_to_unlocked_tier(): void
    {
        $user = User::factory()->create();
        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'deso',
            'unlock_code' => '0114C1010',
            'price_paid' => 7900,
        ]);

        $tier = $this->tiering->resolveUserTier($user, '0114C1010');

        $this->assertEquals(DataTier::Unlocked, $tier);
    }

    public function test_kommun_unlock_covers_all_desos_in_kommun(): void
    {
        $user = User::factory()->create();
        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'kommun',
            'unlock_code' => '0114',
            'price_paid' => 19900,
        ]);

        // Any DeSO starting with 0114 should be unlocked
        $tier = $this->tiering->resolveUserTier($user, '0114C1010');
        $this->assertEquals(DataTier::Unlocked, $tier);

        // Different DeSO in same kommun
        $tier2 = $this->tiering->resolveUserTier($user, '0114C2020');
        $this->assertEquals(DataTier::Unlocked, $tier2);

        // DeSO in different kommun - not unlocked
        $tier3 = $this->tiering->resolveUserTier($user, '0180C1010');
        $this->assertEquals(DataTier::FreeAccount, $tier3);
    }

    public function test_lan_unlock_covers_all_desos_in_lan(): void
    {
        $user = User::factory()->create();
        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'lan',
            'unlock_code' => '01',
            'price_paid' => 99900,
        ]);

        $tier = $this->tiering->resolveUserTier($user, '0114C1010');
        $this->assertEquals(DataTier::Unlocked, $tier);

        $tier2 = $this->tiering->resolveUserTier($user, '0180C5050');
        $this->assertEquals(DataTier::Unlocked, $tier2);

        // Different län
        $tier3 = $this->tiering->resolveUserTier($user, '1280C1010');
        $this->assertEquals(DataTier::FreeAccount, $tier3);
    }

    public function test_unlock_without_deso_code_resolves_to_free(): void
    {
        $user = User::factory()->create();
        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'deso',
            'unlock_code' => '0114C1010',
            'price_paid' => 7900,
        ]);

        // Without specifying a deso code, unlock is not relevant
        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::FreeAccount, $tier);
    }

    public function test_subscriber_takes_priority_over_unlock(): void
    {
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'deso',
            'unlock_code' => '0114C1010',
            'price_paid' => 7900,
        ]);

        $tier = $this->tiering->resolveUserTier($user, '0114C1010');

        // Subscriber > Unlocked
        $this->assertEquals(DataTier::Subscriber, $tier);
    }

    public function test_admin_takes_priority_over_subscriber(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $tier = $this->tiering->resolveUserTier($user);

        $this->assertEquals(DataTier::Admin, $tier);
    }

    // === Band Systems ===

    public function test_percentile_to_5_band(): void
    {
        $this->assertEquals('very_high', $this->tiering->percentileToBand(95));
        $this->assertEquals('very_high', $this->tiering->percentileToBand(80));
        $this->assertEquals('high', $this->tiering->percentileToBand(79));
        $this->assertEquals('high', $this->tiering->percentileToBand(60));
        $this->assertEquals('average', $this->tiering->percentileToBand(50));
        $this->assertEquals('average', $this->tiering->percentileToBand(40));
        $this->assertEquals('low', $this->tiering->percentileToBand(30));
        $this->assertEquals('low', $this->tiering->percentileToBand(20));
        $this->assertEquals('very_low', $this->tiering->percentileToBand(10));
        $this->assertEquals('very_low', $this->tiering->percentileToBand(0));
        $this->assertNull($this->tiering->percentileToBand(null));
    }

    public function test_percentile_to_8_band(): void
    {
        $this->assertEquals('top_5', $this->tiering->percentileToWideBand(97));
        $this->assertEquals('top_5', $this->tiering->percentileToWideBand(95));
        $this->assertEquals('top_10', $this->tiering->percentileToWideBand(92));
        $this->assertEquals('top_10', $this->tiering->percentileToWideBand(90));
        $this->assertEquals('top_25', $this->tiering->percentileToWideBand(80));
        $this->assertEquals('top_25', $this->tiering->percentileToWideBand(75));
        $this->assertEquals('upper_half', $this->tiering->percentileToWideBand(60));
        $this->assertEquals('upper_half', $this->tiering->percentileToWideBand(50));
        $this->assertEquals('lower_half', $this->tiering->percentileToWideBand(40));
        $this->assertEquals('lower_half', $this->tiering->percentileToWideBand(25));
        $this->assertEquals('bottom_25', $this->tiering->percentileToWideBand(15));
        $this->assertEquals('bottom_25', $this->tiering->percentileToWideBand(10));
        $this->assertEquals('bottom_10', $this->tiering->percentileToWideBand(7));
        $this->assertEquals('bottom_10', $this->tiering->percentileToWideBand(5));
        $this->assertEquals('bottom_5', $this->tiering->percentileToWideBand(3));
        $this->assertEquals('bottom_5', $this->tiering->percentileToWideBand(0));
        $this->assertNull($this->tiering->percentileToWideBand(null));
    }

    // === Bar Width Quantization ===

    public function test_bar_width_quantized_to_5_percent(): void
    {
        // 73 → round(73/5)*5 = 75 → 0.75
        $this->assertEquals(0.75, $this->tiering->percentileToBarWidth(73));

        // 42 → round(42/5)*5 = 40 → 0.40
        $this->assertEquals(0.40, $this->tiering->percentileToBarWidth(42));

        // 98 → round(98/5)*5 = 100 → 1.00
        $this->assertEquals(1.0, $this->tiering->percentileToBarWidth(98));

        // 0 → 0
        $this->assertEquals(0.0, $this->tiering->percentileToBarWidth(0));

        // null → 0
        $this->assertEquals(0.0, $this->tiering->percentileToBarWidth(null));
    }

    // === Raw Value Rounding ===

    public function test_round_raw_value_sek(): void
    {
        $result = $this->tiering->roundRawValue(237000, 'SEK');
        $this->assertEquals('~235,000 kr', $result);
    }

    public function test_round_raw_value_percent(): void
    {
        $result = $this->tiering->roundRawValue(73.6, '%');
        $this->assertEquals('~74%', $result);
    }

    public function test_round_raw_value_rate(): void
    {
        $result = $this->tiering->roundRawValue(1234.5, '/100k');
        $this->assertEquals('~1235', $result);
    }

    public function test_round_raw_value_points(): void
    {
        $result = $this->tiering->roundRawValue(217.3, 'points');
        $this->assertEquals('~215', $result);
    }

    public function test_round_raw_value_null(): void
    {
        $this->assertNull($this->tiering->roundRawValue(null, 'SEK'));
    }

    // === Trend Transformation ===

    public function test_trend_to_direction(): void
    {
        $this->assertEquals('improving', $this->tiering->trendToDirection([
            'direction' => 'improving',
            'percent_change' => 5.0,
        ]));

        $this->assertEquals('declining', $this->tiering->trendToDirection([
            'direction' => 'declining',
            'percent_change' => -3.0,
        ]));

        $this->assertNull($this->tiering->trendToDirection(null));
        $this->assertNull($this->tiering->trendToDirection(['direction' => 'insufficient']));
    }

    public function test_trend_to_band(): void
    {
        $this->assertEquals('large', $this->tiering->trendToBand([
            'direction' => 'improving',
            'percent_change' => 15.0,
        ]));

        $this->assertEquals('moderate', $this->tiering->trendToBand([
            'direction' => 'declining',
            'percent_change' => -7.0,
        ]));

        $this->assertEquals('small', $this->tiering->trendToBand([
            'direction' => 'improving',
            'percent_change' => 3.0,
        ]));

        $this->assertEquals('minimal', $this->tiering->trendToBand([
            'direction' => 'stable',
            'percent_change' => 0.5,
        ]));

        $this->assertNull($this->tiering->trendToBand(null));
        $this->assertNull($this->tiering->trendToBand(['direction' => 'insufficient']));
    }

    // === Indicator Transformation ===

    public function test_transform_indicator_public_tier(): void
    {
        $indicators = collect([
            $this->sampleIndicator(),
        ]);

        $result = $this->tiering->transformIndicators($indicators, DataTier::Public);

        $this->assertCount(1, $result);
        $transformed = $result->first();

        $this->assertEquals('median_income', $transformed['slug']);
        $this->assertEquals('Median Income', $transformed['name']);
        $this->assertTrue($transformed['locked']);
        $this->assertArrayNotHasKey('raw_value', $transformed);
        $this->assertArrayNotHasKey('normalized_value', $transformed);
        $this->assertArrayNotHasKey('percentile', $transformed);
        $this->assertArrayNotHasKey('band', $transformed);
    }

    public function test_transform_indicator_free_account_tier(): void
    {
        $indicators = collect([
            $this->sampleIndicator(),
        ]);

        $result = $this->tiering->transformIndicators($indicators, DataTier::FreeAccount);

        $transformed = $result->first();

        $this->assertEquals('median_income', $transformed['slug']);
        $this->assertFalse($transformed['locked']);
        $this->assertEquals('high', $transformed['band']); // 0.73 * 100 = 73 → high
        $this->assertEquals(0.75, $transformed['bar_width']); // 73 → round(73/5)*5=75 → 0.75
        $this->assertEquals('positive', $transformed['direction']);
        $this->assertArrayNotHasKey('raw_value', $transformed);
        $this->assertArrayNotHasKey('normalized_value', $transformed);
        $this->assertArrayNotHasKey('percentile', $transformed);
    }

    public function test_transform_indicator_unlocked_tier(): void
    {
        $indicators = collect([
            $this->sampleIndicator(),
        ]);

        $result = $this->tiering->transformIndicators($indicators, DataTier::Unlocked);

        $transformed = $result->first();

        $this->assertEquals('median_income', $transformed['slug']);
        $this->assertFalse($transformed['locked']);
        $this->assertEquals('upper_half', $transformed['percentile_band']); // 73 → upper_half (50-74)
        $this->assertEquals('~235,000 kr', $transformed['raw_value_approx']);
        $this->assertArrayNotHasKey('raw_value', $transformed);
        $this->assertArrayNotHasKey('normalized_value', $transformed);
    }

    public function test_transform_indicator_subscriber_tier(): void
    {
        $indicators = collect([
            $this->sampleIndicator(),
        ]);

        $result = $this->tiering->transformIndicators($indicators, DataTier::Subscriber);

        $transformed = $result->first();

        $this->assertEquals('median_income', $transformed['slug']);
        $this->assertFalse($transformed['locked']);
        $this->assertEquals(73.0, $transformed['percentile']);
        $this->assertNotNull($transformed['raw_value']);
        $this->assertNotNull($transformed['normalized_value']);
        $this->assertArrayHasKey('methodology_note', $transformed);
        $this->assertArrayHasKey('source_url', $transformed);
    }

    public function test_transform_indicator_admin_tier(): void
    {
        $indicator = $this->sampleIndicator();
        $indicator['weight'] = 0.09;
        $indicator['weighted_contribution'] = 6.57;
        $indicator['rank'] = 1547;
        $indicator['rank_total'] = 6160;
        $indicator['source_api_path'] = '/OV0104/v1/doris/sv/ssd/HE0110/HE0110G/TabVX1DeSO';
        $indicator['admin_notes'] = 'Key income indicator';

        $indicators = collect([$indicator]);

        $result = $this->tiering->transformIndicators($indicators, DataTier::Admin);

        $transformed = $result->first();

        // Has all subscriber fields
        $this->assertNotNull($transformed['percentile']);
        $this->assertNotNull($transformed['raw_value']);

        // Plus admin extras
        $this->assertEquals(0.09, $transformed['weight']);
        $this->assertEquals(6.57, $transformed['weighted_contribution']);
        $this->assertEquals(1547, $transformed['rank']);
        $this->assertEquals(6160, $transformed['rank_total']);
        $this->assertNotNull($transformed['source_api_path']);
        $this->assertEquals('Key income indicator', $transformed['admin_notes']);
    }

    public function test_enterprise_tier_same_as_subscriber(): void
    {
        $indicators = collect([$this->sampleIndicator()]);

        $subscriber = $this->tiering->transformIndicators($indicators, DataTier::Subscriber);
        $enterprise = $this->tiering->transformIndicators($indicators, DataTier::Enterprise);

        $this->assertEquals($subscriber->first(), $enterprise->first());
    }

    // === API Endpoint Tiering ===

    public function test_indicators_api_public_tier_returns_locked(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->createDeso();

        $response = $this->getJson('/api/deso/0114C1010/indicators?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 0);

        $indicators = $response->json('indicators');
        foreach ($indicators as $ind) {
            $this->assertTrue($ind['locked']);
            $this->assertArrayNotHasKey('raw_value', $ind);
            $this->assertArrayNotHasKey('normalized_value', $ind);
        }
    }

    public function test_indicators_api_free_tier_returns_bands(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->createDeso();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/deso/0114C1010/indicators?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 1);

        $indicators = $response->json('indicators');
        foreach ($indicators as $ind) {
            $this->assertFalse($ind['locked']);
            $this->assertArrayHasKey('band', $ind);
            $this->assertArrayHasKey('bar_width', $ind);
            $this->assertArrayNotHasKey('raw_value', $ind);
            $this->assertArrayNotHasKey('percentile', $ind);
        }
    }

    public function test_indicators_api_free_tier_includes_unlock_options(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->createDeso();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/deso/0114C1010/indicators?year=2024');

        $response->assertOk();
        $response->assertJsonStructure([
            'unlock_options' => [
                'deso' => ['code', 'price'],
                'kommun' => ['code', 'price'],
            ],
        ]);
    }

    public function test_indicators_api_subscriber_tier_returns_full_data(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->createDeso();
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/deso/0114C1010/indicators?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 3);

        $indicators = $response->json('indicators');
        foreach ($indicators as $ind) {
            $this->assertFalse($ind['locked']);
            $this->assertArrayHasKey('percentile', $ind);
            $this->assertArrayHasKey('methodology_note', $ind);
        }
    }

    public function test_crime_api_public_returns_locked(): void
    {
        $response = $this->getJson('/api/deso/0114C1010/crime?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 0);
        $response->assertJsonPath('locked', true);
    }

    public function test_crime_api_free_returns_bands(): void
    {
        $this->createDeso();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/deso/0114C1010/crime?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 1);
        $response->assertJsonPath('locked', false);
        $response->assertJsonStructure(['crime_band', 'safety_band']);
    }

    public function test_financial_api_public_returns_locked(): void
    {
        $response = $this->getJson('/api/deso/0114C1010/financial?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 0);
        $response->assertJsonPath('locked', true);
    }

    public function test_financial_api_free_returns_band(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/deso/0114C1010/financial?year=2024');

        $response->assertOk();
        $response->assertJsonPath('tier', 1);
        $response->assertJsonPath('locked', false);
        $response->assertJsonStructure(['debt_band']);
    }

    public function test_schools_api_public_returns_count_only(): void
    {
        $response = $this->getJson('/api/deso/0114C1010/schools');

        $response->assertOk();
        $response->assertJsonPath('tier', 0);
        $response->assertJsonPath('schools', []);
        $response->assertJsonStructure(['school_count']);
    }

    public function test_schools_api_free_returns_count_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/deso/0114C1010/schools');

        $response->assertOk();
        $response->assertJsonPath('tier', 1);
        $response->assertJsonPath('schools', []);
    }

    public function test_pois_api_public_returns_locked(): void
    {
        $response = $this->getJson('/api/deso/0114C1010/pois');

        $response->assertOk();
        $response->assertJsonPath('tier', 0);
        $response->assertJsonPath('locked', true);
    }

    // === Admin Middleware ===

    public function test_admin_middleware_blocks_non_admin(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.indicators'));

        $response->assertForbidden();
    }

    public function test_admin_middleware_blocks_guest(): void
    {
        $response = $this->get(route('admin.indicators'));

        $response->assertRedirect();
    }

    public function test_admin_middleware_allows_admin(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.indicators'));

        $response->assertOk();
    }

    // === DataTier Enum ===

    public function test_data_tier_values_are_ordered(): void
    {
        $this->assertLessThan(DataTier::FreeAccount->value, DataTier::Public->value);
        $this->assertLessThan(DataTier::Unlocked->value, DataTier::FreeAccount->value);
        $this->assertLessThan(DataTier::Subscriber->value, DataTier::Unlocked->value);
        $this->assertLessThan(DataTier::Enterprise->value, DataTier::Subscriber->value);
        $this->assertLessThan(DataTier::Admin->value, DataTier::Enterprise->value);
    }

    public function test_data_tier_comparison_works(): void
    {
        $this->assertTrue(DataTier::Subscriber->value >= DataTier::Unlocked->value);
        $this->assertTrue(DataTier::Admin->value >= DataTier::Subscriber->value);
        $this->assertFalse(DataTier::Public->value >= DataTier::FreeAccount->value);
    }

    // === User Model ===

    public function test_user_is_admin_returns_correct_value(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $regular = User::factory()->create(['is_admin' => false]);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($regular->isAdmin());
    }

    public function test_user_has_active_subscription(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasActiveSubscription());

        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 34900,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->assertTrue($user->hasActiveSubscription());
    }

    public function test_user_has_unlocked_deso(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->hasUnlocked('0114C1010'));

        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'deso',
            'unlock_code' => '0114C1010',
            'price_paid' => 7900,
        ]);

        $this->assertTrue($user->hasUnlocked('0114C1010'));
        $this->assertFalse($user->hasUnlocked('0114C2020'));
    }

    public function test_user_has_unlocked_kommun(): void
    {
        $user = User::factory()->create();

        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'kommun',
            'unlock_code' => '0114',
            'price_paid' => 19900,
        ]);

        $this->assertTrue($user->hasUnlocked('0114C1010'));
        $this->assertTrue($user->hasUnlocked('0114C9999'));
        $this->assertFalse($user->hasUnlocked('0180C1010'));
    }

    public function test_user_has_unlocked_lan(): void
    {
        $user = User::factory()->create();

        UserUnlock::create([
            'user_id' => $user->id,
            'unlock_type' => 'lan',
            'unlock_code' => '01',
            'price_paid' => 99900,
        ]);

        $this->assertTrue($user->hasUnlocked('0114C1010'));
        $this->assertTrue($user->hasUnlocked('0180C5050'));
        $this->assertFalse($user->hasUnlocked('1280C1010'));
    }

    // === View As (Admin Tier Override) ===

    public function test_admin_can_set_view_as_tier(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/view-as', ['tier' => 1]);

        $response->assertRedirect();
        $this->assertEquals(1, session('viewAs'));
    }

    public function test_admin_can_clear_view_as_tier(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/view-as', ['tier' => 1]);
        $this->assertEquals(1, session('viewAs'));

        $response = $this->actingAs($admin)->delete('/admin/view-as');

        $response->assertRedirect();
        $this->assertNull(session('viewAs'));
    }

    public function test_non_admin_cannot_set_view_as_tier(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->post('/admin/view-as', ['tier' => 0]);

        $response->assertForbidden();
    }

    public function test_guest_cannot_set_view_as_tier(): void
    {
        $response = $this->post('/admin/view-as', ['tier' => 0]);

        $response->assertRedirect();
    }

    public function test_view_as_invalid_tier_ignored(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/view-as', ['tier' => 99]);
        $this->assertNull(session('viewAs'));

        $this->actingAs($admin)->post('/admin/view-as', ['tier' => -1]);
        $this->assertNull(session('viewAs'));
    }

    public function test_resolve_effective_tier_respects_view_as(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Without override, admin gets Admin tier
        $this->assertEquals(DataTier::Admin, $this->tiering->resolveEffectiveTier($admin));

        // Set session override
        session(['viewAs' => 0]);
        $this->assertEquals(DataTier::Public, $this->tiering->resolveEffectiveTier($admin));

        session(['viewAs' => 1]);
        $this->assertEquals(DataTier::FreeAccount, $this->tiering->resolveEffectiveTier($admin));

        session(['viewAs' => 3]);
        $this->assertEquals(DataTier::Subscriber, $this->tiering->resolveEffectiveTier($admin));

        // Clear override
        session()->forget('viewAs');
        $this->assertEquals(DataTier::Admin, $this->tiering->resolveEffectiveTier($admin));
    }

    public function test_view_as_does_not_affect_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        session(['viewAs' => 3]);
        $this->assertEquals(DataTier::FreeAccount, $this->tiering->resolveEffectiveTier($user));
    }

    public function test_map_page_respects_view_as_override(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Set view-as to Free Account
        $this->actingAs($admin)->post('/admin/view-as', ['tier' => 1]);

        $response = $this->actingAs($admin)->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('userTier', 1)
            ->where('isAuthenticated', true)
        );
    }

    public function test_admin_can_still_access_admin_routes_while_viewing_as_lower_tier(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);

        // Set view-as to Public
        $this->actingAs($admin)->post('/admin/view-as', ['tier' => 0]);

        // Should still be able to access admin routes
        $response = $this->actingAs($admin)->get(route('admin.indicators'));
        $response->assertOk();
    }

    public function test_api_endpoints_respect_view_as_override(): void
    {
        $this->seed(\Database\Seeders\IndicatorSeeder::class);
        $this->createDeso();
        $admin = User::factory()->create(['is_admin' => true]);

        // Without override — admin tier
        $response = $this->actingAs($admin)->getJson('/api/deso/0114C1010/indicators?year=2024');
        $response->assertOk();
        $response->assertJsonPath('tier', 99);

        // Set view-as to Public
        $this->actingAs($admin)->post('/admin/view-as', ['tier' => 0]);

        // Now should return public tier data
        $response = $this->actingAs($admin)->getJson('/api/deso/0114C1010/indicators?year=2024');
        $response->assertOk();
        $response->assertJsonPath('tier', 0);
        $indicators = $response->json('indicators');
        foreach ($indicators as $ind) {
            $this->assertTrue($ind['locked']);
        }
    }

    public function test_view_as_shared_via_inertia(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // No override — viewingAs should be null
        $response = $this->actingAs($admin)->get('/');
        $response->assertInertia(fn ($page) => $page
            ->where('viewingAs', null)
        );

        // Set override
        $this->actingAs($admin)->post('/admin/view-as', ['tier' => 1]);

        $response = $this->actingAs($admin)->get('/');
        $response->assertInertia(fn ($page) => $page
            ->where('viewingAs', 1)
        );
    }

    // === Login Redirect ===

    public function test_login_page_stores_redirect_param_as_intended_url(): void
    {
        $response = $this->get('/login?redirect=/admin/pipeline');

        $response->assertOk();
        $this->assertEquals('/admin/pipeline', session('url.intended'));
    }

    public function test_login_page_without_redirect_does_not_set_intended(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertNull(session('url.intended'));
    }

    // === Map Page Tier Propagation ===

    public function test_map_page_passes_user_tier_for_guest(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('userTier', 0)
            ->where('isAuthenticated', false)
        );
    }

    public function test_map_page_passes_user_tier_for_free_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('userTier', 1)
            ->where('isAuthenticated', true)
        );
    }

    public function test_map_page_passes_user_tier_for_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('userTier', 99)
            ->where('isAuthenticated', true)
        );
    }

    private function createDeso(string $desoCode = '0114C1010'): void
    {
        DB::table('deso_areas')->insert([
            'deso_code' => $desoCode,
            'deso_name' => 'Test DeSO',
            'kommun_code' => substr($desoCode, 0, 4),
            'kommun_name' => 'Upplands Väsby',
            'lan_code' => substr($desoCode, 0, 2),
            'lan_name' => 'Stockholms län',
            'area_km2' => 1.5,
            'trend_eligible' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleIndicator(): array
    {
        return [
            'slug' => 'median_income',
            'name' => 'Median Income',
            'category' => 'economic',
            'raw_value' => 237000,
            'normalized_value' => 0.73,
            'unit' => 'SEK',
            'direction' => 'positive',
            'normalization_scope' => 'national',
            'description_short' => 'Median disposable income',
            'description_long' => 'Detailed methodology explanation',
            'methodology_note' => 'SCB data methodology',
            'national_context' => 'National median: 250,000 SEK',
            'source_name' => 'SCB',
            'source_url' => 'https://scb.se',
            'data_vintage' => '2024',
            'trend' => [
                'direction' => 'improving',
                'percent_change' => 3.5,
            ],
            'history' => [
                ['year' => 2021, 'value' => 215000],
                ['year' => 2022, 'value' => 224000],
                ['year' => 2023, 'value' => 230000],
                ['year' => 2024, 'value' => 237000],
            ],
        ];
    }
}
