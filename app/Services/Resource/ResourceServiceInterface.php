<?php

namespace App\Services\Resource;

use App\Models\Order;

/**
 * 资源方服务接口
 * 定义统一的资源方服务接口，支持不同资源方的实现
 */
interface ResourceServiceInterface
{
    /**
     * 接单（确认订单）
     * 
     * @param Order $order 订单
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public function confirmOrder(Order $order): array;

    /**
     * 拒单（拒绝订单）
     * 
     * @param Order $order 订单
     * @param string $reason 拒绝原因
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public function rejectOrder(Order $order, string $reason): array;

    /**
     * 核销订单
     * 
     * @param Order $order 订单
     * @param array $data 核销数据 ['use_start_date' => string, 'use_end_date' => string, 'use_quantity' => int, ...]
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public function verifyOrder(Order $order, array $data): array;

    /**
     * 取消订单
     * 
     * @param Order $order 订单
     * @param string $reason 取消原因
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public function cancelOrder(Order $order, string $reason): array;

    /**
     * 查询订单是否可以取消
     * 
     * @param Order $order 订单
     * @return array [
     *     'can_cancel' => bool,  // 是否可以取消
     *     'message' => string,   // 原因说明
     *     'data' => array        // 额外数据
     * ]
     */
    public function canCancelOrder(Order $order): array;
}

