<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo data (php artisan demo:seed)
    |--------------------------------------------------------------------------
    |
    | allow_in_production: the command refuses to run when APP_ENV=production
    | unless DEMO_SEED_ALLOW=true.
    |
    | password: the password of every demo account. When empty, the command
    | generates a random one on each run and prints it once. Never commit it.
    |
    */

    'allow_in_production' => filter_var(env('DEMO_SEED_ALLOW', false), FILTER_VALIDATE_BOOL),

    'password' => env('DEMO_SEED_PASSWORD'),

];
