<?php

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;

class CleanupExpiredPurchases extends Command
{
    protected $signature = 'purchase:cleanup';

    protected $description = 'Expire abandoned checkout sessions older than 2 hours';

    public function handle(): void
    {
        $expired = Report::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(2))
            ->update(['status' => 'expired']);

        $this->info("Expired {$expired} abandoned checkouts.");
    }
}
