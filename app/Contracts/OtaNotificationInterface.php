<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * OTA平台订单状态通知接口
 * 用于统一不同OTA平台的通知逻辑
 */
interface OtaNotificationInterface
{
    /**
     * 通知订单确认（出票成功）
     * 
     * @param Order $order 订单
     * @return void
     */
    public function notifyOrderConfirmed(Order $order): void;

    /**
     * 通知订单退款（取消确认）
     * 
     * @param Order $order 订单
     * @return void
     */
    public function notifyOrderRefunded(Order $order): void;

    /**
     * 通知订单核销（已使用）
     * 
     * @param Order $order 订单
     * @param array $data 额外数据
     * @return void
     */
    public function notifyOrderConsumed(Order $order, array $data = []): void;
}

