<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Facturapi (PAC) — Fase 6.1
    |--------------------------------------------------------------------------
    |
    | Infraestructura de adaptador únicamente: en esta fase no se realiza
    | ningún timbrado real ni llamada real al PAC. `test_key` es la llave
    | de entorno de pruebas de Facturapi (nunca la llave live) — jamás se
    | expone al frontend ni se registra en logs/excepciones.
    |
    */

    'facturapi' => [
        'base_url' => env('FACTURAPI_BASE_URL', 'https://www.facturapi.io/v2'),
        'test_key' => env('FACTURAPI_TEST_KEY'),
        'timeout' => (int) env('FACTURAPI_TIMEOUT', 15),
        'connect_timeout' => (int) env('FACTURAPI_CONNECT_TIMEOUT', 5),
    ],

];
