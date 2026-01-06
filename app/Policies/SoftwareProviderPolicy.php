<?php

namespace App\Policies;

use App\Models\SoftwareProvider;
use App\Models\User;

class SoftwareProviderPolicy
{
    /**
     * 所有已认证用户都可以查看系统服务商列表（用于下拉选择等场景）
     * 但只有管理员可以进行创建、更新、删除操作
     */
    public function viewAny(User $user): bool
    {
        // 所有已认证用户都可以查看列表（用于下拉选择等场景）
        return true;
    }

    /**
     * 仅超级管理员可以查看系统服务商详情
     */
    public function view(User $user, SoftwareProvider $softwareProvider): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以创建系统服务商
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以更新系统服务商
     */
    public function update(User $user, SoftwareProvider $softwareProvider): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以删除系统服务商
     */
    public function delete(User $user, SoftwareProvider $softwareProvider): bool
    {
        return $user->isAdmin();
    }
}