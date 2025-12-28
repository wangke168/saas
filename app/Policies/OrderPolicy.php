<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * 确定用户是否可以查看订单列表
     */
    public function viewAny(User $user): bool
    {
        // 所有已认证用户都可以查看订单列表（权限控制在 Controller 中通过查询过滤实现）
        return true;
    }

    /**
     * 确定用户是否可以查看特定订单
     */
    public function view(User $user, Order $order): bool
    {
        // 超级管理员可以查看所有订单
        if ($user->isAdmin()) {
            return true;
        }

        // 运营只能查看所属资源方下的所有景区下的订单
        if ($user->isOperator()) {
            // 通过订单的产品关联找到景区
            if ($order->product && $order->product->scenic_spot_id) {
                $resourceProviderIds = $user->resourceProviders->pluck('id');
                $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                    $query->whereIn('resource_providers.id', $resourceProviderIds);
                })->pluck('id');
                
                return $scenicSpotIds->contains($order->product->scenic_spot_id);
            }
        }

        return false;
    }

    /**
     * 确定用户是否可以更新订单状态
     * 注意：订单状态更新权限较高，只有管理员可以操作
     */
    public function updateStatus(User $user, Order $order): bool
    {
        // 只有超级管理员可以更新订单状态
        // 运营人员不应该有修改订单状态的权限，这会影响业务流程和财务数据
        return $user->isAdmin();
    }
}