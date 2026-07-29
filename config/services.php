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

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
    ],

    'telegram-bot-api' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'max-bot-api' => [
        'token' => env('MAX_BOT_TOKEN'),
    ],

    'max' => [
        'bot_token' => env('MAX_BOT_TOKEN'),
        'webhook_url' => env('MAX_WEBHOOK_URL') ?: env('APP_URL'),
        'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
    ],

    'yandex_delivery' => [
        'enabled' => env('YANDEX_DELIVERY_ENABLED', false),
        'mode' => env('YANDEX_DELIVERY_MODE', 'sandbox'),
        'token' => env('YANDEX_DELIVERY_TOKEN'),
        'platform_station_id' => env('YANDEX_DELIVERY_PLATFORM_STATION_ID'),
        'merchant_id' => env('YANDEX_DELIVERY_MERCHANT_ID'),
        'geocoder_key' => env('YANDEX_DELIVERY_GEOCODER_KEY'),
        'base_url' => [
            'sandbox' => 'https://b2b.taxi.tst.yandex.net',
            'production' => 'https://b2b-authproxy.taxi.yandex.net',
        ],
        'packaging' => [
            'default' => [
                'weight' => 0.5,
                'length' => 0.2,
                'width' => 0.1,
                'height' => 0.1,
            ],
            'bulk_threshold' => 5,
        ],
        'logs_retention_days' => env('YANDEX_DELIVERY_LOGS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Публичные имена ботов/сообществ для deeplink-привязки чата
    |--------------------------------------------------------------------------
    |
    | Используются при построении диплинков (start/ref) для привязки переписки
    | из мессенджеров к клиенту/заказу. См. docs/tasks/messenger-deeplink-binding.md
    | и App\Services\Messaging\ChatBindingService::buildLinks().
    |
    */
    'messenger_deeplinks' => [
        // Telegram: https://t.me/<username>?start=<TOKEN>
        'telegram_bot' => env('TELEGRAM_BOT_USERNAME', 'againdev_test_bot'),
        // MAX: https://max.ru/<public_name>?start=<TOKEN>
        'max_bot' => env('MAX_BOT_PUBLIC_NAME', 'id4707052811_bot'),
        // VK: https://vk.me/<screen_name>?ref=<TOKEN> (по умолчанию public<community_id>)
        'vk_screen_name' => env('VK_SCREEN_NAME'),
    ],
];
