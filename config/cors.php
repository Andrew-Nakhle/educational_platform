<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The framework defaults, except for the allowed origins:
| CORS_ALLOWED_ORIGINS is a comma-separated list of origins (scheme, host
| and port, no trailing slash), for example
| "https://app.example.test,https://admin.example.test".
|
| Unset or empty: every origin is allowed ("*"), exactly as before this
| file existed. Production must set it to the frontend origin.
|
*/

$origins = array_values(array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
