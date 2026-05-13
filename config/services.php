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
        'notification_enabled' => filter_var(env('DINGTALK_NOTIFICATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | 飞猪分销（测试命令等使用）
    |--------------------------------------------------------------------------
    */
    'fliggy_distribution' => [
        'id' => env('FLIGGY_DISTRIBUTION_ID'),
        'private_key' => env('FLIGGY_DISTRIBUTION_PRIVATE_KEY'),
        'api_url' => env('FLIGGY_DISTRIBUTION_API_URL', 'https://pre-api.alitrip.alibaba.com'),
        'username' => env('FLIGGY_DISTRIBUTION_USERNAME', ''),
        'password' => env('FLIGGY_DISTRIBUTION_PASSWORD', ''),
        'test_product_id' => env('FLIGGY_TEST_PRODUCT_ID', ''),
        'test_product_id_2' => env('FLIGGY_TEST_PRODUCT_ID_2', ''),
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

    /*
    |--------------------------------------------------------------------------
    | 横店资源（PMS）对接
    |--------------------------------------------------------------------------
    |
    | 业务代码仅使用 config()，以便 config:cache 后仍能读取 .env 中的值。
    |
    */
    'hengdian' => [
        'api_url' => env('HENGDIAN_API_URL'),
        'username' => env('HENGDIAN_USERNAME'),
        'password' => env('HENGDIAN_PASSWORD'),
        'webhook_url' => env('HENGDIAN_WEBHOOK_URL'),
        'book_amount_unit' => env('HENGDIAN_BOOK_AMOUNT_UNIT', 'yuan'),
        'ctrip_username' => env('HENGDIAN_CTRIP_USERNAME', ''),
        'ctrip_password' => env('HENGDIAN_CTRIP_PASSWORD', ''),
        'meituan_username' => env('HENGDIAN_MEITUAN_USERNAME', ''),
        'meituan_password' => env('HENGDIAN_MEITUAN_PASSWORD', ''),
        'fliggy_username' => env('HENGDIAN_FLIGGY_USERNAME', ''),
        'fliggy_password' => env('HENGDIAN_FLIGGY_PASSWORD', ''),
    ],

];
