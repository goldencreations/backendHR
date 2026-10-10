<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The frontend is served from a different origin than the API, so every
    | browser request needs an explicit allowlist. Listed here rather than
    | wildcarded: these endpoints expose payslips, contracts and identity
    | documents.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://hr.goldencreations.online'))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Required for cookie authentication. A browser only stores and resends
    | a cookie when the server answers with Access-Control-Allow-Credentials,
    | and the origin allowlist above must stay explicit because a wildcard is
    | not permitted alongside credentials.
    */
    'supports_credentials' => true,

];
