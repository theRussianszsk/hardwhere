<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// Release stock held by marketplace reservations that were never collected. Overdue ones are also released lazily at checkout.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('reservations:expire')->everyMinute()->withoutOverlapping();
