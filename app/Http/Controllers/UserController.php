<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * 用户列表（仅超级管理员）
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $users = $query->with('resourceProviders') // 改为 resourceProviders
            ->paginate($request->get('per_page', 15));

        // Laravel 会自动序列化关联关系，with('resourceProviders') 会序列化为 resource_providers
        // 不需要额外的转换，Laravel 会自动处理

        return response()->json($users);
    }

    /**
     * 用户详情（仅超级管理员）
     */
    public function show(User $user): JsonResponse
    {
        $user->load('resourceProviders');
        
        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * 创建用户（仅超级管理员）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'role' => ['required', Rule::enum(UserRole::class)],
            'resource_provider_ids' => 'nullable|array', // 改为 resource_provider_ids
            'resource_provider_ids.*' => 'exists:resource_providers,id',
        ]);

        // 验证运营用户必须绑定至少一个资源方
        $this->validateOperatorResourceProviders($validated['role'], $validated['resource_provider_ids'] ?? null);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (isset($validated['resource_provider_ids']) && !empty($validated['resource_provider_ids'])) {
            $user->resourceProviders()->attach($validated['resource_provider_ids']);
        }

        $user->load('resourceProviders');

        return response()->json([
            'message' => '用户创建成功',
            'user' => $user,
        ], 201);
    }

    /**
     * 更新用户（仅超级管理员）
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|min:8',
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => 'sometimes|boolean',
            'resource_provider_ids' => 'nullable|array', // 改为 resource_provider_ids
            'resource_provider_ids.*' => 'exists:resource_providers,id',
        ]);

        // 安全保护：禁止禁用超级管理员（包括自己）
        if ($user->isAdmin() && isset($validated['is_active']) && $validated['is_active'] === false) {
            return response()->json([
                'message' => '不能禁用超级管理员',
            ], 403);
        }

        // 不能修改超级管理员的角色
        if ($user->isAdmin() && isset($validated['role']) && $validated['role'] !== UserRole::ADMIN->value) {
            return response()->json([
                'message' => '不能修改超级管理员的角色',
            ], 403);
        }

        // 确定最终的角色（如果修改了角色，使用新角色；否则使用原角色）
        $finalRole = $validated['role'] ?? $user->role->value;

        // 验证运营用户必须绑定至少一个资源方
        $this->validateOperatorResourceProviders($finalRole, $validated['resource_provider_ids'] ?? null, $user);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // 同步资源方绑定
        // 使用 array_key_exists 而不是 isset，这样即使传递空数组也会处理
        if (array_key_exists('resource_provider_ids', $validated)) {
            \Illuminate\Support\Facades\Log::info('用户更新：同步资源方绑定', [
                'user_id' => $user->id,
                'role' => $finalRole,
                'resource_provider_ids' => $validated['resource_provider_ids'],
            ]);
            
            if ($finalRole === UserRole::ADMIN->value) {
                // 超级管理员不需要绑定资源方，清空绑定
                $user->resourceProviders()->detach();
            } else {
                // 运营用户：同步资源方绑定（空数组会清空所有绑定）
                $user->resourceProviders()->sync($validated['resource_provider_ids'] ?? []);
            }
        } else {
            // 如果没有提供 resource_provider_ids，且是运营用户，保持现有绑定不变
            \Illuminate\Support\Facades\Log::info('用户更新：未提供 resource_provider_ids，保持现有绑定', [
                'user_id' => $user->id,
                'role' => $finalRole,
            ]);
        }

        $user->load('resourceProviders');

        return response()->json([
            'message' => '用户更新成功',
            'user' => $user,
        ]);
    }

    /**
     * 禁用用户（仅超级管理员，不能删除）
     */
    public function disable(User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return response()->json([
                'message' => '不能禁用超级管理员',
            ], 403);
        }

        $user->update(['is_active' => false]);

        return response()->json([
            'message' => '用户已禁用',
        ]);
    }

    /**
     * 启用用户（仅超级管理员）
     */
    public function enable(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return response()->json([
            'message' => '用户已启用',
        ]);
    }

    /**
     * 验证运营用户必须绑定至少一个资源方
     *
     * @param string $role 用户角色
     * @param array|null $resourceProviderIds 资源方ID数组（可为null，表示使用现有绑定）
     * @param User|null $user 用户对象（更新时使用，创建时为null）
     * @return void
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function validateOperatorResourceProviders(string $role, ?array $resourceProviderIds, ?User $user = null): void
    {
        if ($role !== UserRole::OPERATOR->value) {
            return;
        }

        // 如果提供了资源方ID，直接验证
        if ($resourceProviderIds !== null) {
            if (empty($resourceProviderIds) || count($resourceProviderIds) === 0) {
                abort(response()->json([
                    'message' => '运营用户必须绑定至少一个资源方',
                    'errors' => [
                        'resource_provider_ids' => ['运营用户必须绑定至少一个资源方'],
                    ],
                ], 422));
            }
            return;
        }

        // 更新时，如果没有提供资源方ID，检查现有绑定
        if ($user !== null) {
            $existingProviderIds = $user->resourceProviders->pluck('id')->toArray();
            if (empty($existingProviderIds) || count($existingProviderIds) === 0) {
                abort(response()->json([
                    'message' => '运营用户必须绑定至少一个资源方',
                    'errors' => [
                        'resource_provider_ids' => ['运营用户必须绑定至少一个资源方'],
                    ],
                ], 422));
            }
        }
    }
}
