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

    // Google: sign-in (Identity Services) and Maps. The browser key must be restricted to your
    // domain in Google Cloud Console; the server key to the Geocoding API (and your server IP).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'maps_browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
        'maps_server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
    ],

    // Facebook Login: App ID (public) and App Secret (server only) from developers.facebook.com.
    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        // Set the same value as "Secret hash" in the Flutterwave dashboard webhook settings.
        'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        'payment_options' => env('FLUTTERWAVE_PAYMENT_OPTIONS', 'mobilemoneyuganda,card'),
    ],

    // Where pay-on-delivery is offered. Districts are matched against Google's reverse-geocoding result.
    'delivery' => [
        'cod_districts' => array_filter(array_map('trim', explode(',', env('COD_DISTRICTS', 'Kampala,Wakiso')))),
        // Local development without a Google key only: treat points within this many km of the
        // shop as inside the zone. 0 = disabled (production must use Google; unknown = no COD).
        'fallback_radius_km' => (float) env('COD_FALLBACK_RADIUS_KM', 0),
        'shop_lat' => (float) env('SHOP_LAT', 0.3476),
        'shop_lng' => (float) env('SHOP_LNG', 32.5825),
    ],

];
