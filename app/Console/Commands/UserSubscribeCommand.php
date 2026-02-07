<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;

class UserSubscribeCommand extends Command
{
    protected $signature = 'user:subscribe
        {email : The user email}
        {--plan=monthly : Subscription plan (monthly or annual)}';

    protected $description = 'Activate a subscription for a user (for testing)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("User not found: {$this->argument('email')}");

            return self::FAILURE;
        }

        $plan = $this->option('plan');
        if (! in_array($plan, ['monthly', 'annual'])) {
            $this->error('Plan must be "monthly" or "annual"');

            return self::FAILURE;
        }

        $price = $plan === 'monthly' ? 34900 : 299000;
        $periodEnd = $plan === 'monthly' ? now()->addMonth() : now()->addYear();

        // Cancel any existing active subscriptions
        Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Subscription::create([
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => 'active',
            'price' => $price,
            'payment_provider' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => $periodEnd,
        ]);

        $this->info("Activated {$plan} subscription for {$user->email} (expires {$periodEnd->toDateString()})");

        return self::SUCCESS;
    }
}
