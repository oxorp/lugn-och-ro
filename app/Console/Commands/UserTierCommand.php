<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DataTieringService;
use Illuminate\Console\Command;

class UserTierCommand extends Command
{
    protected $signature = 'user:tier
        {email : The user email}
        {--deso= : DeSO code to check tier for}';

    protected $description = 'Check a user\'s data tier for a specific DeSO';

    public function handle(DataTieringService $tiering): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("User not found: {$this->argument('email')}");

            return self::FAILURE;
        }

        $desoCode = $this->option('deso');
        $tier = $tiering->resolveUserTier($user, $desoCode);

        $this->info("User: {$user->email}");
        $this->info('Admin: '.($user->isAdmin() ? 'Yes' : 'No'));
        $this->info('Active subscription: '.($user->hasActiveSubscription() ? 'Yes' : 'No'));

        if ($desoCode) {
            $this->info("DeSO: {$desoCode}");
            $this->info('Unlocked: '.($user->hasUnlocked($desoCode) ? 'Yes' : 'No'));
        }

        $this->info("Resolved tier: {$tier->name} ({$tier->value})");

        $unlockCount = $user->unlocks()->count();
        if ($unlockCount > 0) {
            $this->info("Total unlocks: {$unlockCount}");
        }

        return self::SUCCESS;
    }
}
