<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * 仅超级管理员可以查看用户列表
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以查看用户详情
     */
    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以创建用户
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * 仅超级管理员可以更新用户
     */
    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * 不能删除用户，只能禁用
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }
}
