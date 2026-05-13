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

    'dingtalk' => [
        'webhook_url' => env('DINGTALK_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTA 平台（携程 / 美团）
    |--------------------------------------------------------------------------
    |
    | 必须在配置文件中用 env() 读取，业务代码仅允许 config()，
    | 否则执行 php artisan config:cache 后运行时 env() 会返回 null。
    |
    */
    'ctrip' => [
        'scenic_credentials_required' => filter_var(env('CTRIP_SCENIC_CREDENTIALS_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
        'account_id' => env('CTRIP_ACCOUNT_ID'),
        'secret_key' => env('CTRIP_SECRET_KEY'),
        'encrypt_key' => env('CTRIP_ENCRYPT_KEY', ''),
        'encrypt_iv' => env('CTRIP_ENCRYPT_IV', ''),
        'price_api_url' => env('CTRIP_PRICE_API_URL'),
        'stock_api_url' => env('CTRIP_STOCK_API_URL'),
        'order_api_url' => env('CTRIP_ORDER_API_URL'),
        'webhook_url' => env('CTRIP_WEBHOOK_URL', ''),
        'auto_accept_when_sufficient' => filter_var(env('CTRIP_AUTO_ACCEPT_WHEN_SUFFICIENT', true), FILTER_VALIDATE_BOOLEAN),
        'auto_accept_stock_buffer' => (int) env('CTRIP_AUTO_ACCEPT_STOCK_BUFFER', 5),
    ],

    'meituan' => [
        'partner_id' => env('MEITUAN_PARTNER_ID', 0),
        'app_key' => env('MEITUAN_APP_KEY'),
        'app_secret' => env('MEITUAN_APP_SECRET'),
        'aes_key' => env('MEITUAN_AES_KEY', ''),
        'api_url' => env('MEITUAN_API_URL', 'https://connectivity-adapter.meituan.com'),
        'webhook_url' => env('MEITUAN_WEBHOOK_URL', ''),
        'skip_verify_notification' => filter_var(env('MEITUAN_SKIP_VERIFY_NOTIFICATION', false), FILTER_VALIDATE_BOOLEAN),
        'test_scenic_closed' => filter_var(env('MEITUAN_TEST_SCENIC_CLOSED', false), FILTER_VALIDATE_BOOLEAN),
        'auto_accept_when_sufficient' => filter_var(env('MEITUAN_AUTO_ACCEPT_WHEN_SUFFICIENT', true), FILTER_VALIDATE_BOOLEAN),
        'auto_accept_stock_buffer' => (int) env('MEITUAN_AUTO_ACCEPT_STOCK_BUFFER', 5),
    ],

];
