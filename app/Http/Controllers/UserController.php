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

        $users = $query->with('scenicSpots')
            ->paginate($request->get('per_page', 15));

        return response()->json($users);
    }

    /**
     * 用户详情（仅超级管理员）
     */
    public function show(User $user): JsonResponse
    {
        $user->load('scenicSpots');
        
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
            'scenic_spot_ids' => 'nullable|array',
            'scenic_spot_ids.*' => 'exists:scenic_spots,id',
        ]);

        // 验证运营用户必须绑定至少一个景区
        $this->validateOperatorScenicSpots($validated['role'], $validated['scenic_spot_ids'] ?? null);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (isset($validated['scenic_spot_ids']) && !empty($validated['scenic_spot_ids'])) {
            $user->scenicSpots()->attach($validated['scenic_spot_ids']);
        }

        $user->load('scenicSpots');

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
            'scenic_spot_ids' => 'nullable|array',
            'scenic_spot_ids.*' => 'exists:scenic_spots,id',
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

        // 验证运营用户必须绑定至少一个景区
        $this->validateOperatorScenicSpots($finalRole, $validated['scenic_spot_ids'] ?? null, $user);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // 同步景区绑定
        if (isset($validated['scenic_spot_ids'])) {
            if ($finalRole === UserRole::ADMIN->value) {
                // 超级管理员不需要绑定景区，清空绑定
                $user->scenicSpots()->detach();
            } else {
                $user->scenicSpots()->sync($validated['scenic_spot_ids']);
            }
        }

        $user->load('scenicSpots');

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
     * 验证运营用户必须绑定至少一个景区
     *
     * @param string $role 用户角色
     * @param array|null $scenicSpotIds 景区ID数组（可为null，表示使用现有绑定）
     * @param User|null $user 用户对象（更新时使用，创建时为null）
     * @return void
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function validateOperatorScenicSpots(string $role, ?array $scenicSpotIds, ?User $user = null): void
    {
        if ($role !== UserRole::OPERATOR->value) {
            return;
        }

        // 如果提供了景区ID，直接验证
        if ($scenicSpotIds !== null) {
            if (empty($scenicSpotIds) || count($scenicSpotIds) === 0) {
                abort(response()->json([
                    'message' => '运营用户必须绑定至少一个景区',
                    'errors' => [
                        'scenic_spot_ids' => ['运营用户必须绑定至少一个景区'],
                    ],
                ], 422));
            }
            return;
        }

        // 更新时，如果没有提供景区ID，检查现有绑定
        if ($user !== null) {
            $existingSpotIds = $user->scenicSpots->pluck('id')->toArray();
            if (empty($existingSpotIds) || count($existingSpotIds) === 0) {
                abort(response()->json([
                    'message' => '运营用户必须绑定至少一个景区',
                    'errors' => [
                        'scenic_spot_ids' => ['运营用户必须绑定至少一个景区'],
                    ],
                ], 422));
            }
        }
    }
}
