<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modelos Personalizados
    |--------------------------------------------------------------------------
    |
    | Aquí puedes especificar los modelos que el paquete utilizará para las
    | entidades principales. Puedes sobrescribir estos valores en tu archivo
    | .env si estás utilizando modelos personalizados.
    |
    */
    'models' => [
        'facebook_page' => \ScriptDevelop\InstagramApiManager\Models\FacebookPage::class,

        'instagram_business_account' => \ScriptDevelop\InstagramApiManager\Models\InstagramBusinessAccount::class,

        'instagram_contact' => \ScriptDevelop\InstagramApiManager\Models\InstagramContact::class,

        //Mensajes
        'message' => \ScriptDevelop\InstagramApiManager\Models\InstagramMessage::class,

        'instagram_profile' => \ScriptDevelop\InstagramApiManager\Models\InstagramProfile::class,

        'instagram_referral' => \ScriptDevelop\InstagramApiManager\Models\InstagramReferral::class,

        'messenger_contact' => \ScriptDevelop\InstagramApiManager\Models\MessengerContact::class,

        'messenger_conversation' => \ScriptDevelop\InstagramApiManager\Models\MessengerConversation::class,

        //Mensajes messenger
        'messenger_message' => \ScriptDevelop\InstagramApiManager\Models\MessengerMessage::class,

        'meta_app' => \ScriptDevelop\InstagramApiManager\Models\MetaApp::class,

        // Modelo para autenticacion Oauth
        'oauth_state' => \ScriptDevelop\InstagramApiManager\Models\OauthState::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Instagram API Configuration
    |--------------------------------------------------------------------------
    */
    'api' => [
        // URLs base para diferentes tipos de endpoints
        'oauth_base_url' => env('INSTAGRAM_OAUTH_BASE_URL', 'https://graph.facebook.com'),
        'graph_base_url' => env('INSTAGRAM_GRAPH_BASE_URL', 'https://graph.instagram.com'),

        'version' => env('INSTAGRAM_API_VERSION', 'v19.0'),
        'timeout' => env('INSTAGRAM_API_TIMEOUT', 30),
        'retry_attempts' => env('INSTAGRAM_API_RETRY_ATTEMPTS', 3),
    ],

    'webhook' => [
        'verify_token' => env('INSTAGRAM_WEBHOOK_VERIFY_TOKEN', 'default_token'),
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
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect_uri' => env('INSTAGRAM_REDIRECT_URI'),
    ]
];