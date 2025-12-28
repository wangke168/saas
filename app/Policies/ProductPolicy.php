<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * 确定用户是否可以查看任何产品
     */
    public function viewAny(User $user): bool
    {
        // 所有已认证用户都可以查看产品列表（权限控制在 Controller 中通过查询过滤实现）
        return true;
    }

    /**
     * 确定用户是否可以查看特定产品
     */
    public function view(User $user, Product $product): bool
    {
        // 超级管理员可以查看所有产品
        if ($user->isAdmin()) {
            return true;
        }

        // 运营只能查看所属资源方下的所有景区下的产品
        if ($user->isOperator()) {
            $resourceProviderIds = $user->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            return $scenicSpotIds->contains($product->scenic_spot_id);
        }

        return false;
    }

    /**
     * 确定用户是否可以创建产品
     * 
     * @param User $user
     * @param mixed $scenicSpotId 景区ID（通过 authorize 的第二个参数传递）
     */
    public function create(User $user, $scenicSpotId = null): bool
    {
        // 超级管理员可以创建产品
        if ($user->isAdmin()) {
            return true;
        }

        // 运营只能在自己所属资源方下的景区下创建产品
        if ($user->isOperator() && $scenicSpotId !== null) {
            $resourceProviderIds = $user->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            return $scenicSpotIds->contains($scenicSpotId);
        }

        return false;
    }

    /**
     * 确定用户是否可以更新产品
     * 
     * @param User $user
     * @param Product $product
     * @param mixed $newScenicSpotId 新景区ID（通过 authorize 的第三个参数传递）
     */
    public function update(User $user, Product $product, $newScenicSpotId = null): bool
    {
        // 超级管理员可以更新所有产品
        if ($user->isAdmin()) {
            return true;
        }

        // 运营只能更新所属资源方下的所有景区下的产品
        if ($user->isOperator()) {
            $resourceProviderIds = $user->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            // 检查当前产品的景区权限
            if (! $scenicSpotIds->contains($product->scenic_spot_id)) {
                return false;
            }

            // 如果修改了景区，检查新景区的权限
            if ($newScenicSpotId !== null && $newScenicSpotId != $product->scenic_spot_id) {
                return $scenicSpotIds->contains($newScenicSpotId);
            }

            return true;
        }

        return false;
    }

    /**
     * 确定用户是否可以删除产品
     */
    public function delete(User $user, Product $product): bool
    {
        // 超级管理员可以删除所有产品
        if ($user->isAdmin()) {
            return true;
        }

        // 运营只能删除所属资源方下的所有景区下的产品
        if ($user->isOperator()) {
            $resourceProviderIds = $user->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            return $scenicSpotIds->contains($product->scenic_spot_id);
        }

        return false;
    }

    /**
     * 确定用户是否可以恢复产品
     */
    public function restore(User $user, Product $product): bool
    {
        // 恢复权限与删除权限相同
        return $this->delete($user, $product);
    }

    /**
     * 确定用户是否可以永久删除产品
     */
    public function forceDelete(User $user, Product $product): bool
    {
        // 只有超级管理员可以永久删除
        return $user->isAdmin();
    }
}
