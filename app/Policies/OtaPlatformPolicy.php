<?php

namespace App\Policies;

use App\Models\OtaPlatform;
use App\Models\User;

class OtaPlatformPolicy
{
    /**
     * 仅超级管理员可以查看OTA平台列表
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以查看OTA平台详情
     */
    public function view(User $user, OtaPlatform $otaPlatform): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以创建OTA平台
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以更新OTA平台
     */
    public function update(User $user, OtaPlatform $otaPlatform): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以删除OTA平台
     */
    public function delete(User $user, OtaPlatform $otaPlatform): bool
    {
        return $user->isAdmin();
    }
}
