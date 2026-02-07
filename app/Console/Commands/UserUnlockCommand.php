<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class UserUnlockCommand extends Command
{
    protected $signature = 'user:unlock
        {email : The user email}
        {--deso= : DeSO code to unlock}
        {--kommun= : Kommun code to unlock (all DeSOs in kommun)}
        {--lan= : Län code to unlock (all DeSOs in län)}';

    protected $description = 'Grant a user an area unlock for testing';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("User not found: {$this->argument('email')}");

            return self::FAILURE;
        }

        $type = null;
        $code = null;

        if ($this->option('deso')) {
            $type = 'deso';
            $code = $this->option('deso');
        } elseif ($this->option('kommun')) {
            $type = 'kommun';
            $code = $this->option('kommun');
        } elseif ($this->option('lan')) {
            $type = 'lan';
            $code = $this->option('lan');
        } else {
            $this->error('Specify --deso, --kommun, or --lan');

            return self::FAILURE;
        }

        $user->unlocks()->updateOrCreate(
            ['unlock_type' => $type, 'unlock_code' => $code],
            ['price_paid' => 0, 'payment_reference' => 'manual_grant'],
        );

        $this->info("Granted {$type} unlock ({$code}) to {$user->email}");

        return self::SUCCESS;
    }
}
