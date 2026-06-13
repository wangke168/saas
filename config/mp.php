<?php

return [
    /** 小程序待支付预约超时（分钟），超时自动取消并恢复权益为待预约 */
    'payment_timeout_minutes' => (int) env('MP_PAYMENT_TIMEOUT_MINUTES', 10),
];
