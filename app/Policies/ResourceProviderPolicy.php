<?php

namespace App\Policies;

use App\Models\ResourceProvider;
use App\Models\User;

class ResourceProviderPolicy
{
    /**
     * 只有超级管理员可以管理资源方
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ResourceProvider $resourceProvider): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ResourceProvider $resourceProvider): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ResourceProvider $resourceProvider): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, ResourceProvider $resourceProvider): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, ResourceProvider $resourceProvider): bool
    {
        return $user->isAdmin();
    }
}
