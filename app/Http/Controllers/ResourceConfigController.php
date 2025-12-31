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
            // 如果没有配置，返回 null，让前端知道没有配置过
            return response()->json([
                'success' => true,
                'data' => null,
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
        
        // 如果 auth 中有敏感信息，也隐藏
        if (isset($configData['extra_config']['auth'])) {
            if (isset($configData['extra_config']['auth']['password']) && !empty($configData['extra_config']['auth']['password'])) {
                $configData['extra_config']['auth']['password'] = '***EXISTS***';
            }
            if (isset($configData['extra_config']['auth']['appsecret']) && !empty($configData['extra_config']['auth']['appsecret'])) {
                $configData['extra_config']['auth']['appsecret'] = '***EXISTS***';
            }
            if (isset($configData['extra_config']['auth']['token']) && !empty($configData['extra_config']['auth']['token'])) {
                $configData['extra_config']['auth']['token'] = '***EXISTS***';
            }
            if (isset($configData['extra_config']['auth']['access_token']) && !empty($configData['extra_config']['auth']['access_token'])) {
                $configData['extra_config']['auth']['access_token'] = '***EXISTS***';
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
            'username' => [
                'sometimes',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($scenicSpot) {
                    if ($value && $scenicSpot->software_provider_id) {
                        // 检查同一软件服务商下，username 是否已被其他景区使用
                        $exists = ResourceConfig::where('software_provider_id', $scenicSpot->software_provider_id)
                            ->where('username', $value)
                            ->where('id', '!=', $scenicSpot->resource_config_id)
                            ->exists();
                        
                        if ($exists) {
                            $fail('该用户名已被同一软件服务商下的其他景区使用');
                        }
                    }
                },
            ],
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
            // 新增：认证方式配置
            'auth' => 'nullable|array',
            'auth.type' => 'nullable|string|in:username_password,appkey_secret,token,custom',
            'auth.appkey' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($scenicSpot) {
                    if ($value && $scenicSpot->software_provider_id) {
                        // 检查同一软件服务商下，appkey 是否已被其他景区使用
                        $exists = ResourceConfig::where('software_provider_id', $scenicSpot->software_provider_id)
                            ->whereJsonContains('extra_config->auth->appkey', $value)
                            ->where('id', '!=', $scenicSpot->resource_config_id)
                            ->exists();
                        
                        if ($exists) {
                            $fail('该 AppKey 已被同一软件服务商下的其他景区使用');
                        }
                    }
                },
            ],
            'auth.appsecret' => 'nullable|string|max:255',
            'auth.app_id' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($scenicSpot) {
                    if ($value && $scenicSpot->software_provider_id) {
                        // 检查同一软件服务商下，app_id 是否已被其他景区使用
                        $exists = ResourceConfig::where('software_provider_id', $scenicSpot->software_provider_id)
                            ->whereJsonContains('extra_config->auth->app_id', $value)
                            ->where('id', '!=', $scenicSpot->resource_config_id)
                            ->exists();
                        
                        if ($exists) {
                            $fail('该 AppID 已被同一软件服务商下的其他景区使用');
                        }
                    }
                },
            ],
            'auth.token' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($scenicSpot) {
                    if ($value && $scenicSpot->software_provider_id) {
                        // 检查同一软件服务商下，token 是否已被其他景区使用
                        $exists = ResourceConfig::where('software_provider_id', $scenicSpot->software_provider_id)
                            ->where(function($query) use ($value) {
                                $query->whereJsonContains('extra_config->auth->token', $value)
                                      ->orWhereJsonContains('extra_config->auth->access_token', $value);
                            })
                            ->where('id', '!=', $scenicSpot->resource_config_id)
                            ->exists();
                        
                        if ($exists) {
                            $fail('该 Token 已被同一软件服务商下的其他景区使用');
                        }
                    }
                },
            ],
            'auth.access_token' => 'nullable|string|max:255',
            'auth.custom_params' => 'nullable|array',
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
                
                // 构建 extra_config，包含认证信息
                $extraConfig = [
                    'sync_mode' => $validated['sync_mode'],
                    'order_provider' => $validated['order_provider'] ?? null,
                    'credentials' => $credentials,
                ];
                
                // 如果有认证配置，添加到 extra_config
                if (isset($validated['auth']) && !empty($validated['auth'])) {
                    $extraConfig['auth'] = $validated['auth'];
                }
                
                $config = ResourceConfig::create([
                    'software_provider_id' => $scenicSpot->software_provider_id,
                    'scenic_spot_id' => $scenicSpot->id,
                    'username' => $validated['username'] ?? '',
                    'password' => $validated['password'] ?? '',
                    'api_url' => $validated['api_url'] ?? '',
                    'environment' => $validated['environment'] ?? 'production',
                    'is_active' => $validated['is_active'] ?? true,
                    'extra_config' => $extraConfig,
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
                
                // 合并 extra_config
                $existingExtraConfig = $config->extra_config ?? [];
                $updatedExtraConfig = array_merge($existingExtraConfig, [
                    'sync_mode' => $validated['sync_mode'],
                    'order_provider' => $validated['order_provider'] ?? null,
                    'credentials' => $existingCredentials,
                ]);
                
                // 如果有认证配置，合并到 extra_config
                if (isset($validated['auth']) && !empty($validated['auth'])) {
                    $updatedExtraConfig['auth'] = array_merge(
                        $existingExtraConfig['auth'] ?? [],
                        $validated['auth']
                    );
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
                    'extra_config' => $updatedExtraConfig,
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
                    $this->doSubscribeInventory($scenicSpot, $config);
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
     * 订阅库存推送（房态订阅）- 内部方法
     * 
     * 根据横店系统接口文档（storage/docs/hengdian/hengdian.txt），订阅后横店系统会定期推送房态信息到指定的Webhook地址。
     * 
     * 重要说明：
     * 1. hotel_id 应使用横店系统的酒店ID（短编号，如 001, 002, 2078），存储在 hotel.external_code 字段
     * 2. room_type 必须使用横店系统的房型名称（存储在 room_type.external_code 字段，如果为空则使用 name）
     * 3. 参考文档：storage/docs/hengdian/hotel.txt（酒店ID映射表，用于订阅接口）
     * 4. Webhook接收地址需要在 .env 中配置 HENGDIAN_WEBHOOK_URL
     * 
     * @param ScenicSpot $scenicSpot 景区对象
     * @param ResourceConfig $config 资源配置对象
     * @throws \Exception 订阅失败时抛出异常
     */
    protected function doSubscribeInventory(ScenicSpot $scenicSpot, ResourceConfig $config): void
    {
        $notifyUrl = env('HENGDIAN_WEBHOOK_URL');
        if (!$notifyUrl) {
            throw new \Exception('Webhook URL未配置，请在.env中设置HENGDIAN_WEBHOOK_URL。示例：https://your-domain.com/api/webhooks/resource/hengdian/inventory');
        }

        Log::info('开始订阅横店房态推送', [
            'scenic_spot_id' => $scenicSpot->id,
            'scenic_spot_name' => $scenicSpot->name,
            'notify_url' => $notifyUrl,
        ]);

        // 获取该景区下的所有酒店和房型
        $hotels = $scenicSpot->hotels()->with('roomTypes')->get();
        
        if ($hotels->isEmpty()) {
            Log::warning('订阅库存推送：景区下没有酒店', [
                'scenic_spot_id' => $scenicSpot->id,
            ]);
            return;
        }

        $hotelsData = [];
        $skippedHotels = [];
        
        foreach ($hotels as $hotel) {
            // 优先使用 external_code（横店系统的酒店ID），如果没有则使用 code
            // 重要说明：
            // 1. 根据文档，订阅接口应使用 hotel.txt 中的酒店ID（短编号，如 001, 002, 2078）
            // 2. hotel.external_code 中应直接存储酒店ID（短编号），参考 hotel.txt
            $hotelId = $hotel->external_code ?? $hotel->code;
            if (!$hotelId) {
                $skippedHotels[] = [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'reason' => '缺少external_code和code',
                ];
                Log::warning('订阅库存推送：跳过酒店（缺少编号）', [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                ]);
                continue;
            }


            $roomTypes = [];
            foreach ($hotel->roomTypes as $roomType) {
                // 优先使用 external_code（横店系统的房型名称），如果没有则使用 name
                // 注意：根据映射表，应该使用"房型名称"，建议在 room_type.external_code 中存储
                $roomTypeName = $roomType->external_code ?? $roomType->name;
                if ($roomTypeName) {
                    $roomTypes[] = $roomTypeName;
                }
            }

            if (!empty($roomTypes)) {
                $hotelsData[] = [
                    'hotel_id' => (string)$hotelId,  // 横店系统的酒店ID（短编号）
                    'room_types' => $roomTypes,
                ];
            } else {
                $skippedHotels[] = [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'reason' => '没有有效的房型',
                ];
                Log::warning('订阅库存推送：跳过酒店（无有效房型）', [
                    'hotel_id' => $hotel->id,
                    'hotel_name' => $hotel->name,
                    'hotel_code' => $hotelId,
                ]);
            }
        }

        if (empty($hotelsData)) {
            $message = '没有可用的酒店和房型数据。已跳过 ' . count($skippedHotels) . ' 个酒店';
            Log::warning('订阅库存推送：没有可用数据', [
                'scenic_spot_id' => $scenicSpot->id,
                'total_hotels' => $hotels->count(),
                'skipped_hotels' => $skippedHotels,
            ]);
            throw new \Exception($message);
        }

        Log::info('订阅库存推送：准备提交订阅请求', [
            'scenic_spot_id' => $scenicSpot->id,
            'valid_hotels_count' => count($hotelsData),
            'total_room_types' => array_sum(array_map(function($h) {
                return count($h['room_types']);
            }, $hotelsData)),
            'skipped_hotels_count' => count($skippedHotels),
        ]);

        // 调用订阅接口
        $hengdianService = app(\App\Services\Resource\HengdianService::class);
        $result = $hengdianService->subscribeInventory($hotelsData, $notifyUrl, false);

        if (!($result['success'] ?? false)) {
            $errorMessage = $result['message'] ?? '订阅失败';
            Log::error('订阅库存推送失败', [
                'scenic_spot_id' => $scenicSpot->id,
                'result' => $result,
            ]);
            throw new \Exception($errorMessage);
        }

        Log::info('订阅库存推送成功', [
            'scenic_spot_id' => $scenicSpot->id,
            'hotels_count' => count($hotelsData),
            'message' => $result['message'] ?? '',
        ]);
    }

    /**
     * 手动触发订阅库存推送
     * 
     * 用于在新增酒店或房型后，手动触发向横店系统订阅房态推送。
     * 订阅会包含该景区下的所有有效酒店和房型。
     * 
     * @param ScenicSpot $scenicSpot 景区对象
     * @return JsonResponse
     */
    public function subscribeInventory(ScenicSpot $scenicSpot): JsonResponse
    {
        try {
            $config = $scenicSpot->resourceConfig;
            
            if (!$config) {
                return response()->json([
                    'success' => false,
                    'message' => '景区尚未配置资源方接口，请先配置资源方接口',
                ], 400);
            }

            // 检查库存同步方式是否为推送
            $syncMode = $config->extra_config['sync_mode']['inventory'] ?? null;
            if ($syncMode !== 'push') {
                return response()->json([
                    'success' => false,
                    'message' => '当前库存同步方式不是"资源方推送"，无需订阅。如需订阅，请先将库存同步方式改为"资源方推送"',
                ], 400);
            }

            // 调用订阅方法
            $this->doSubscribeInventory($scenicSpot, $config);

            return response()->json([
                'success' => true,
                'message' => '订阅成功',
            ]);
        } catch (\Exception $e) {
            Log::error('手动触发订阅库存推送失败', [
                'scenic_spot_id' => $scenicSpot->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '订阅失败：' . $e->getMessage(),
            ], 500);
        }
    }
}
