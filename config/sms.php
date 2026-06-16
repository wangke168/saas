<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 短信驱动
    |--------------------------------------------------------------------------
    |
    | aliyun：阿里云短信（生产）
    | log：仅写日志，不实际发送（本地联调）
    |
    */
    'driver' => env('SMS_DRIVER', env('APP_ENV') === 'local' ? 'log' : 'aliyun'),

    'timeout' => (float) env('SMS_TIMEOUT', 5.0),

    'aliyun' => [
        'access_key_id' => env('ALIYUN_SMS_ACCESS_KEY_ID', ''),
        'access_key_secret' => env('ALIYUN_SMS_ACCESS_KEY_SECRET', ''),
        'sign_name' => env('ALIYUN_SMS_SIGN_NAME', ''),
        'template_code' => env('ALIYUN_SMS_TEMPLATE_CODE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | 小程序登录验证码
    |--------------------------------------------------------------------------
    */
    'mp_login' => [
        'code_ttl_seconds' => (int) env('SMS_MP_LOGIN_CODE_TTL', 300),
        'resend_interval_seconds' => (int) env('SMS_MP_LOGIN_RESEND_INTERVAL', 60),
        'daily_limit_per_phone' => (int) env('SMS_MP_LOGIN_DAILY_LIMIT_PHONE', 10),
        'daily_limit_per_ip' => (int) env('SMS_MP_LOGIN_DAILY_LIMIT_IP', 30),
        'expose_debug_code' => filter_var(
            env('SMS_EXPOSE_DEBUG_CODE', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL,
        ),
        // 本地联调：指定手机号可用固定验证码登录，生产环境务必关闭
        'bypass_enabled' => filter_var(
            env('SMS_MP_LOGIN_BYPASS_ENABLED', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL,
        ),
        'bypass_phone' => env('SMS_MP_LOGIN_BYPASS_PHONE', '13605725464'),
        'bypass_code' => env('SMS_MP_LOGIN_BYPASS_CODE', '888888'),
    ],

];
