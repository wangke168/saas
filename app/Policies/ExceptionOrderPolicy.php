<?php

namespace App\Policies;

use App\Models\ExceptionOrder;
use App\Models\User;

class ExceptionOrderPolicy
{
    /**
     * 确定用户是否可以查看异常订单列表
     */
    public function viewAny(User $user): bool
    {
        // 所有已认证用户都可以查看异常订单列表（权限控制在 Controller 中通过查询过滤实现）
        return true;
    }

    /**
     * 确定用户是否可以查看特定异常订单
     */
    public function view(User $user, ExceptionOrder $exceptionOrder): bool
    {
        // 超级管理员可以查看所有异常订单
        if ($user->isAdmin()) {
            return true;
        }

        // 运营只能查看所属资源方下的所有景区下的异常订单
        if ($user->isOperator()) {
            $order = $exceptionOrder->order;
            // 通过订单的产品关联找到景区
            if ($order && $order->product && $order->product->scenic_spot_id) {
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
     * 确定用户是否可以处理异常订单
     */
    public function process(User $user, ExceptionOrder $exceptionOrder): bool
    {
        // 复用 view() 方法的权限检查逻辑
        return $this->view($user, $exceptionOrder);
    }
}

