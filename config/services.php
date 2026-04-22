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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => 'http://localhost:8000/auth/google/callback',
    ],

    'ggdeals' => [
        'api_key' => env('API_KEY_GG_DEALS'),
    ],

    'sistema-estoque' => [
        'base_url' => env('THIS_URL'),
        'dev_base_url' => env('DEV_THIS_URL'),
    ],
    
    'price_researcher' => [
        'base_url' => env('API_PRICE_RESEARCHER'),
        'dev_base_url' => env('DEV_API_PRICE_RESEARCHER'),
    ],

    'carca_api_gamivo' => [
        'base_url' => env('CARCA_API_GAMIVO_URL'),
        'dev_base_url' => env('DEV_CARCA_API_GAMIVO_URL'),
    ],

    'vip_webhook' => [
        'secret' => env('VIP_WEBHOOK_SECRET'),
    ],
];
