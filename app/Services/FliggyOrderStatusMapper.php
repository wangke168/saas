<?php

namespace App\Services;

use App\Enums\OrderStatus;

class FliggyOrderStatusMapper
{
    /**
     * 飞猪状态 -> 系统状态
     * 
     * 飞猪状态码：
     * 1001 - 已创建
     * 1002 - 已支付
     * 1003 - 出票成功
     * 1004 - 出票失败
     * 1005 - 交易完成/核销
     * 1010 - 订单关闭
     * 
     * @param int $fliggyStatus 飞猪订单状态
     * @return OrderStatus|null 系统订单状态，如果无法映射返回null
     */
    public static function mapToOurStatus(int $fliggyStatus): ?OrderStatus
    {
        return match($fliggyStatus) {
            1001 => OrderStatus::CONFIRMING,      // 已创建 -> 确认中
            1002 => OrderStatus::PAID_PENDING,     // 已支付 -> 待确认
            1003 => OrderStatus::CONFIRMED,       // 出票成功 -> 预订成功
            1004 => null,                          // 出票失败 -> 需要创建异常订单
            1005 => OrderStatus::VERIFIED,       // 交易完成/核销 -> 核销订单
            1010 => OrderStatus::CANCEL_APPROVED, // 订单关闭 -> 取消通过
            default => null,
        };
    }
    
    /**
     * 系统状态 -> 飞猪状态（用于查询时的状态筛选）
     * 
     * @param OrderStatus $ourStatus 系统订单状态
     * @return array|null 飞猪状态码数组，如果无法映射返回null
     */
    public static function mapToFliggyStatus(OrderStatus $ourStatus): ?array
    {
        return match($ourStatus) {
            OrderStatus::CONFIRMING => [1001],           // 确认中 -> 已创建
            OrderStatus::PAID_PENDING => [1002],         // 待确认 -> 已支付
            OrderStatus::CONFIRMED => [1003],           // 预订成功 -> 出票成功
            OrderStatus::VERIFIED => [1005],            // 核销订单 -> 交易完成
            OrderStatus::CANCEL_APPROVED => [1010],      // 取消通过 -> 订单关闭
            default => null,
        };
    }
    
    /**
     * 获取飞猪状态的中文描述
     * 
     * @param int $fliggyStatus 飞猪订单状态
     * @return string 状态描述
     */
    public static function getStatusDescription(int $fliggyStatus): string
    {
        return match($fliggyStatus) {
            1001 => '已创建',
            1002 => '已支付',
            1003 => '出票成功',
            1004 => '出票失败',
            1005 => '交易完成/核销',
            1010 => '订单关闭',
            default => '未知状态',
        };
    }
}

