<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Facebook API Configuration
    |--------------------------------------------------------------------------
    */
    'api' => [
        'base_url' => env('FACEBOOK_API_BASE_URL', 'https://graph.facebook.com'),
        'version' => env('FACEBOOK_API_VERSION', 'v19.0'),
        'timeout' => env('FACEBOOK_API_TIMEOUT', 30),
        'retry_attempts' => env('FACEBOOK_API_RETRY_ATTEMPTS', 3),

        'webhook_verify_token' => env('FACEBOOK_WEBHOOK_VERIFY_TOKEN', 'default_token'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Meta OAuth
    |--------------------------------------------------------------------------
    |
    | Credenciales y parámetros para la autenticación con Meta/Facebook.
    |
    */
    'meta_auth' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
    ],

];