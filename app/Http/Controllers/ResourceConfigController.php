<?php

namespace App\Http\Controllers;

use App\Models\ResourceConfig;
use App\Models\ScenicSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResourceConfigController extends Controller
{
    /**
     * 获取景区的资源配置
     */
    public function show(ScenicSpot $scenicSpot): JsonResponse
    {
        $config = $scenicSpot->resourceConfig;

        if (!$config) {
            // 如果没有配置，返回默认值（从.env读取）
            $configData = [
                'api_url' => env('HENGDIAN_API_URL', ''),
                'username' => env('HENGDIAN_USERNAME', ''),
                'password' => '', // 密码不返回
                'environment' => 'production',
                'is_active' => true,
                'extra_config' => [
                    'sync_mode' => [
                        'inventory' => 'manual',
                        'price' => 'manual',
                        'order' => 'manual',
                    ],
                    'order_provider' => null,
                    'credentials' => [
                        'ctrip' => [
                            'username' => env('HENGDIAN_CTRIP_USERNAME', ''),
                            'password' => '',
                        ],
                        'meituan' => [
                            'username' => env('HENGDIAN_MEITUAN_USERNAME', ''),
                            'password' => '',
                        ],
                        'fliggy' => [
                            'username' => env('HENGDIAN_FLIGGY_USERNAME', ''),
                            'password' => '',
                        ],
                    ],
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $configData,
            ]);
        }

        // 隐藏敏感信息（密码）
        $configData = $config->toArray();
        if (!empty($config->password)) {
            // 如果密码存在，返回一个特殊标记，表示密码已设置（但不返回实际密码）
            $configData['password'] = '***EXISTS***';
        } else {
            unset($configData['password']);
        }
        
        // 如果 credentials 中有密码，也隐藏
        if (isset($configData['extra_config']['credentials'])) {
            foreach ($configData['extra_config']['credentials'] as $platform => $cred) {
                if (isset($cred['password']) && !empty($cred['password'])) {
                    $configData['extra_config']['credentials'][$platform]['password'] = '***EXISTS***';
                } else {
                    $configData['extra_config']['credentials'][$platform]['password'] = '';
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $configData,
        ]);
    }

    /**
     * 创建或更新景区的资源配置
     */
    public function store(Request $request, ScenicSpot $scenicSpot): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'sometimes|string|max:255',
            'password' => 'nullable|string|max:255', // 改为 nullable，允许不发送或发送 null
            'api_url' => 'sometimes|url|max:255',
            'environment' => 'sometimes|string|in:production',
            'is_active' => 'sometimes|boolean',
            'sync_mode' => 'required|array',
            'sync_mode.inventory' => 'required|string|in:push,manual',
            'sync_mode.price' => 'required|string|in:push,manual',
            'sync_mode.order' => 'required|string|in:auto,manual,other',
            'order_provider' => 'nullable|exists:software_providers,id',
            'credentials' => 'nullable|array',
            'credentials.*.username' => 'nullable|string|max:255',
            'credentials.*.password' => 'nullable|string|max:255',
        ]);

        // 如果某些字段为空，从.env读取默认值
        if (empty($validated['api_url'])) {
            $validated['api_url'] = env('HENGDIAN_API_URL', '');
        }
        if (empty($validated['username'])) {
            $validated['username'] = env('HENGDIAN_USERNAME', '');
        }
        // 密码处理：如果未提供或为空，从现有配置中获取
        if (!isset($validated['password']) || empty($validated['password'])) {
            // 如果密码未提供或为空，从现有配置中获取，或者从.env读取
            $existingConfig = $scenicSpot->resourceConfig;
            if ($existingConfig && $existingConfig->password) {
                $validated['password'] = $existingConfig->password; // 保持原密码
            } else {
                $validated['password'] = env('HENGDIAN_PASSWORD', '');
            }
        }
        if (empty($validated['environment'])) {
            $validated['environment'] = 'production';
        }

        try {
            DB::beginTransaction();

            // 获取或创建配置
            $config = $scenicSpot->resourceConfig;
            if (!$config) {
                // 创建新配置
                // 确保 credentials 正确初始化
                $credentials = $validated['credentials'] ?? [];
                // 如果 credentials 为空，初始化默认结构
                if (empty($credentials)) {
                    $credentials = [
                        'ctrip' => ['username' => '', 'password' => ''],
                        'meituan' => ['username' => '', 'password' => ''],
                        'fliggy' => ['username' => '', 'password' => ''],
                    ];
                }
                
                $config = ResourceConfig::create([
                    'software_provider_id' => $scenicSpot->software_provider_id,
                    'scenic_spot_id' => $scenicSpot->id,
                    'username' => $validated['username'] ?? '',
                    'password' => $validated['password'] ?? '',
                    'api_url' => $validated['api_url'] ?? '',
                    'environment' => $validated['environment'] ?? 'production',
                    'is_active' => $validated['is_active'] ?? true,
                    'extra_config' => [
                        'sync_mode' => $validated['sync_mode'],
                        'order_provider' => $validated['order_provider'] ?? null,
                        'credentials' => $credentials,
                    ],
                ]);

                // 更新景区的 resource_config_id
                $scenicSpot->update([
                    'resource_config_id' => $config->id,
                    'is_system_connected' => $validated['sync_mode']['order'] === 'auto',
                ]);
            } else {
                // 更新现有配置
                // 合并 credentials（保留现有值，只更新有值的字段）
                $existingCredentials = $config->extra_config['credentials'] ?? [];
                $newCredentials = $validated['credentials'] ?? [];
                
                // 只更新有值的字段
                foreach ($newCredentials as $platform => $cred) {
                    if (!isset($existingCredentials[$platform])) {
                        $existingCredentials[$platform] = [];
                    }
                    // 如果新值中有用户名，更新用户名
                    if (isset($cred['username'])) {
                        $existingCredentials[$platform]['username'] = $cred['username'];
                    }
                    // 如果新值中有密码且不为空，更新密码；如果为空，保留现有密码
                    if (isset($cred['password'])) {
                        if ($cred['password'] !== '' && $cred['password'] !== '***EXISTS***') {
                            $existingCredentials[$platform]['password'] = $cred['password'];
                        }
                        // 如果密码为空字符串或 '***EXISTS***'，不更新（保留现有密码）
                    }
                }
                
                $config->update([
                    'username' => $validated['username'] ?? $config->username,
                    // 如果密码未提供或为空，保留现有密码
                    'password' => (!empty($validated['password']) && $validated['password'] !== '***EXISTS***') 
                        ? $validated['password'] 
                        : $config->password,
                    'api_url' => $validated['api_url'] ?? $config->api_url,
                    'environment' => $validated['environment'] ?? $config->environment,
                    'is_active' => $validated['is_active'] ?? $config->is_active,
                    'extra_config' => array_merge($config->extra_config ?? [], [
                        'sync_mode' => $validated['sync_mode'],
                        'order_provider' => $validated['order_provider'] ?? null,
                        'credentials' => $existingCredentials,
                    ]),
                ]);

                // 更新景区的 is_system_connected
                $scenicSpot->update([
                    'is_system_connected' => $validated['sync_mode']['order'] === 'auto',
                ]);
            }

            DB::commit();

            // 如果库存同步方式为推送，自动订阅
            if ($validated['sync_mode']['inventory'] === 'push') {
                try {
                    $this->subscribeInventory($scenicSpot, $config);
                } catch (\Exception $e) {
                    Log::warning('自动订阅库存推送失败', [
                        'scenic_spot_id' => $scenicSpot->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $configData = $config->toArray();
            unset($configData['password']);

            return response()->json([
                'success' => true,
                'message' => '资源配置保存成功',
                'data' => $configData,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('保存资源配置失败', [
                'scenic_spot_id' => $scenicSpot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '保存失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 订阅库存推送
     */
    protected function subscribeInventory(ScenicSpot $scenicSpot, ResourceConfig $config): void
    {
        $notifyUrl = env('HENGDIAN_WEBHOOK_URL');
        if (!$notifyUrl) {
            throw new \Exception('Webhook URL未配置，请在.env中设置HENGDIAN_WEBHOOK_URL');
        }

        // 获取该景区下的所有酒店和房型
        $hotels = $scenicSpot->hotels()->with('roomTypes')->get();
        
        $hotelsData = [];
        foreach ($hotels as $hotel) {
            $hotelId = $hotel->external_code ?? $hotel->code;
            if (!$hotelId) {
                continue;
            }

            $roomTypes = [];
            foreach ($hotel->roomTypes as $roomType) {
                $roomTypeName = $roomType->external_code ?? $roomType->name;
                if ($roomTypeName) {
                    $roomTypes[] = $roomTypeName;
                }
            }

            if (!empty($roomTypes)) {
                $hotelsData[] = [
                    'hotel_id' => $hotelId,
                    'room_types' => $roomTypes,
                ];
            }
        }

        if (empty($hotelsData)) {
            Log::warning('订阅库存推送：没有可用的酒店和房型', [
                'scenic_spot_id' => $scenicSpot->id,
            ]);
            return;
        }

        // 调用订阅接口
        $hengdianService = app(\App\Services\Resource\HengdianService::class);
        $result = $hengdianService->subscribeInventory($hotelsData, $notifyUrl, false);

        if (!($result['success'] ?? false)) {
            throw new \Exception($result['message'] ?? '订阅失败');
        }
    }
}
