<?php

use App\Support\WechatEnv;

return [

    /*
    |--------------------------------------------------------------------------
    | 微信小程序（C 端预约登录、支付）
    |--------------------------------------------------------------------------
    */
    'mini_program' => [
        'app_id' => WechatEnv::value('WECHAT_MINI_APP_ID'),
        'app_secret' => WechatEnv::value('WECHAT_MINI_APP_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 微信支付（小程序 JSAPI / 预约补差）
    |--------------------------------------------------------------------------
    */
    'pay' => [
        'mch_id' => WechatEnv::value('WECHAT_PAY_MCH_ID'),
        'mch_serial' => WechatEnv::value('WECHAT_PAY_MCH_SERIAL'),
        'private_key_path' => WechatEnv::path('WECHAT_PAY_PRIVATE_KEY_PATH'),
        'mch_cert_path' => WechatEnv::path(
            'WECHAT_PAY_MCH_CERT_PATH',
            WechatEnv::value('WECHAT_PAY_PRIVATE_KEY_PATH'),
        ),
        'api_v3_key' => WechatEnv::value('WECHAT_PAY_API_V3_KEY'),
        'notify_url' => WechatEnv::value('WECHAT_PAY_NOTIFY_URL'),
        'public_key_id' => WechatEnv::value('WECHAT_PAY_PUBLIC_KEY_ID'),
        'public_key_path' => WechatEnv::path('WECHAT_PAY_PUBLIC_KEY_PATH'),
        // 本地联调可保留 mock 回调；生产务必为 false
        'allow_mock_callback' => filter_var(
            env('WECHAT_PAY_ALLOW_MOCK_CALLBACK', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL,
        ),
    ],

];
