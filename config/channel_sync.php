<?php

return [
    'debug_signature' => (bool) env('CHANNEL_SYNC_DEBUG_SIGNATURE', false),

    'providers' => [
        'wuzhen' => [
            'source' => env('WUZHEN_SYNC_SOURCE', 'wuzhen_ota_portal'),
            'secret' => env('WUZHEN_SYNC_SECRET', ''),
            'timestamp_ttl' => (int) env('WUZHEN_SYNC_TTL', 300),
            'auto_push_ota' => (bool) env('WUZHEN_AUTO_PUSH_OTA', true),
            'software_provider_api_type' => env('WUZHEN_SOFTWARE_PROVIDER_API_TYPE', 'wuzhen'),
        ],
    ],
];
