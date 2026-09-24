<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'opensky' => [
        'token_url' => 'https://auth.opensky-network.org/auth/realms/opensky-network/protocol/openid-connect/token',
        'base_url' => 'https://opensky-network.org/api',
        'client_id' => env('OPENSKY_CLIENT_ID'),
        'client_secret' => env('OPENSKY_CLIENT_SECRET'),
        'operational_delay_hours' => (int) env('OPENSKY_OPERATIONAL_DELAY_HOURS', 24),
    ],

    'api_ninjas' => [
        'base_url' => 'https://api.api-ninjas.com/v1',
        'api_key' => env('API_NINJAS_API_KEY'),
        'airports_max_attempts' => (int) env('API_NINJAS_AIRPORTS_MAX_ATTEMPTS', 5),
        'airports_decay_seconds' => (int) env('API_NINJAS_AIRPORTS_DECAY_SECONDS', 60),
        'airport_not_found_cache_seconds' => (int) env('API_NINJAS_AIRPORT_NOT_FOUND_CACHE_SECONDS', 604800),
        'airports_429_fallback_delay_seconds' => (int) env('API_NINJAS_AIRPORTS_429_FALLBACK_DELAY_SECONDS', 60),
    ],

];
