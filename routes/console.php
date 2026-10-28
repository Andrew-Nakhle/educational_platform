<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('payments:expire-unpaid')
    ->everyMinute()
    ->withoutOverlapping();

// Deletes tokens past SANCTUM_TOKEN_EXPIRATION_MINUTES (and any expires_at)
// a day after they expired; nothing to do while expiry is off.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
