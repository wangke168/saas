<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTA 产品目录推送（仅 config() 读取，兼容 php artisan config:cache）
    |--------------------------------------------------------------------------
    */

    'enable_product_push_async' => filter_var(env('ENABLE_PRODUCT_PUSH_ASYNC', false), FILTER_VALIDATE_BOOLEAN),

];
