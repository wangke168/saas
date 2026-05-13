<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 库存推送与 OTA 同步（仅 config() 读取，兼容 php artisan config:cache）
    |--------------------------------------------------------------------------
    */

    'enable_inventory_push_async' => filter_var(env('ENABLE_INVENTORY_PUSH_ASYNC', false), FILTER_VALIDATE_BOOLEAN),

    'fingerprint_ttl_days' => (int) env('INVENTORY_FINGERPRINT_TTL_DAYS', 30),

    'enable_auto_push_inventory_to_ota' => filter_var(env('ENABLE_AUTO_PUSH_INVENTORY_TO_OTA', true), FILTER_VALIDATE_BOOLEAN),

    'enable_auto_push_manual_inventory_to_ota' => filter_var(env('ENABLE_AUTO_PUSH_MANUAL_INVENTORY_TO_OTA', false), FILTER_VALIDATE_BOOLEAN),

    'push_delay_seconds' => (int) env('INVENTORY_PUSH_DELAY_SECONDS', 5),

];
