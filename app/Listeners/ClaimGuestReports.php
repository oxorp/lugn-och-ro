<?php

namespace App\Listeners;

use App\Models\Report;
use Illuminate\Auth\Events\Login;

class ClaimGuestReports
{
    public function handle(Login $event): void
    {
        Report::where('guest_email', $event->user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $event->user->id]);
    }
}
