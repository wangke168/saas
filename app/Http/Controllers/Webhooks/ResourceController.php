<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Inventory;
use App\Models\ResourceSyncLog;
use App\Models\SoftwareProvider;
use App\Models\OtaPlatform;
use App\Enums\PriceSource;
use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Jobs\PushChangedInventoryToOtaJob;
use App\Services\Resource\ScenicSpotIdentificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Redis\Connections\ConnectionException;
use SimpleXMLElement;

class ResourceController extends Controller
{
    /**
     * 接收资源方推送的库存信息（横店系统）
     * 
     * 请求格式：
     * <RoomStatus>
     *     <RoomQuotaMap>[{"hotelNo":"001","roomType":"标准间","roomQuota":[{"date":"2021-10-21","quota":100},{"date":"2021-10-22","quota":0}]}]</RoomQuotaMap>
     * </RoomStatus>
     * 
     * 注意：通过环境变量 ENABLE_INVENTORY_PUSH_ASYNC 控制是否使用异步处理
     * - true: 使用新的异步处理（Redis过滤 + 增量推送）
     * - false: 使用原有同步处理（默认，保持向后兼容）
     */
    public function handleHengdianInventory(Request $request): \Illuminate\Http\Response
    {
        try {
            $rawBody = $request->getContent();
            
            Log::info('资源方库存推送', [
                'body' => $rawBody,
            ]);

            // 获取软件服务商ID（横店系统）
            // 注意：这里通过URL路径识别软件服务商，未来可以扩展为通过路由参数识别
            $softwareProviderId = SoftwareProvider::where('api_type', 'hengdian')->value('id');
            
            if (!$softwareProviderId) {
                Log::error('资源方库存推送：未找到横店软件服务商配置');
                return $this->xmlResponse('-1', '系统配置错误：未找到软件服务商');
            }
            
            // 尝试识别景区（用于日志记录和后续可能的验证）
            $identificationResult = null;
            try {
                // 解析回调数据，提取业务标识
                $xmlObj = new SimpleXMLElement($rawBody);
                
                // 使用辅助方法解析JSON
                $roomQuotaMap = $this->parseJsonFromXml($xmlObj->RoomQuotaMap);
                
                // 从第一个酒店数据中提取 hotelNo
                $callbackData = [];
                if (is_array($roomQuotaMap) && !empty($roomQuotaMap) && isset($roomQuotaMap[0]['hotelNo'])) {
                    $callbackData['hotelNo'] = $roomQuotaMap[0]['hotelNo'];
                }
                
                // 使用识别服务识别景区
                if (!empty($callbackData)) {
                    $identificationResult = ScenicSpotIdentificationService::identify(
                        $request,
                        $callbackData,
                        $softwareProviderId
                    );
                    
                    if ($identificationResult) {
                        Log::info('资源方库存推送：成功识别景区', [
                            'scenic_spot_id' => $identificationResult['scenic_spot']->id,
                            'scenic_spot_name' => $identificationResult['scenic_spot']->name,
                            'identification_method' => $identificationResult['method'],
                            'config_id' => $identificationResult['config']->id,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // 识别失败不影响主流程，只记录日志
                Log::warning('资源方库存推送：识别景区失败', [
                    'error' => $e->getMessage(),
                ]);
            }

            // 检查是否启用异步处理（新功能）
            $useAsync = env('ENABLE_INVENTORY_PUSH_ASYNC', false);
            
            if ($useAsync) {
                // 使用新的异步处理方式，传递软件服务商ID
                return $this->handleHengdianInventoryAsync($rawBody, $softwareProviderId);
            }

            // 使用原有的同步处理方式（保持向后兼容），传递软件服务商ID
            return $this->handleHengdianInventorySync($rawBody, $softwareProviderId);

        } catch (\Exception $e) {
            Log::error('资源方库存推送：处理异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'body' => $request->getContent(),
            ]);

            return $this->xmlResponse('-1', '处理异常：' . $e->getMessage());
        }
    }

    /**
     * 异步处理方式（新功能）
     * 立即返回响应，后台异步处理，使用 Redis 快速过滤
     * 
     * @param string $rawBody XML请求体
     * @param int $softwareProviderId 软件服务商ID，用于过滤酒店（避免不同服务商的酒店external_code冲突）
     */
    protected function handleHengdianInventoryAsync(string $rawBody, int $softwareProviderId): \Illuminate\Http\Response
    {
        try {
            // 将数据放入队列，立即返回响应给景区方
            // 注意：ProcessResourceInventoryPushJob 需要接收 softwareProviderId 参数
            \App\Jobs\ProcessResourceInventoryPushJob::dispatch($rawBody, $softwareProviderId)
                ->onQueue('resource-push'); // 使用专门的队列
            
            Log::info('资源方库存推送：已接收并放入队列（异步处理）', [
                'body_length' => strlen($rawBody),
                'software_provider_id' => $softwareProviderId,
            ]);
            
            // 立即返回，不等待处理完成
            return $this->xmlResponse('0', '已接收');
            
        } catch (\Exception $e) {
            Log::error('资源方库存推送：异步处理失败', [
                'error' => $e->getMessage(),
            ]);
            // 降级到同步处理
            return $this->handleHengdianInventorySync($rawBody, $softwareProviderId);
        }
    }

    /**
     * 从XML节点中解析JSON字符串
     * 
     * 处理各种可能的格式：
     * 1. "[{\"hotelNo\":\"001\",...}]" - 带引号和转义
     * 2. "[{"hotelNo":"001",...}]" - 带引号但无转义
     * 3. [{"hotelNo":"001",...}] - 纯JSON数组
     * 4. " \"[{\"hotelNo\":\"001\",...}]\" " - 前后有空格和引号
     * 
     * @param SimpleXMLElement $xmlNode XML节点
     * @return array|null 解析后的数组，失败返回null
     */
    protected function parseJsonFromXml(SimpleXMLElement $xmlNode): ?array
    {
        $jsonString = trim((string)$xmlNode);
        
        if (empty($jsonString)) {
            return null;
        }
        
        // 记录原始值（用于调试）
        $original = $jsonString;
        
        // 步骤1：去除外层引号（如果存在）
        // 检查是否被引号包裹（支持单引号和双引号）
        if ((str_starts_with($jsonString, '"') && str_ends_with($jsonString, '"')) 
            || (str_starts_with($jsonString, "'") && str_ends_with($jsonString, "'"))) {
            $jsonString = substr($jsonString, 1, -1);
            $jsonString = trim($jsonString); // 去除引号后可能残留的空格
        }
        
        // 步骤2：处理转义字符
        $jsonString = stripslashes($jsonString);
        
        // 步骤3：再次trim（确保没有残留空格）
        $jsonString = trim($jsonString);
        
        // 步骤4：解析JSON
        $result = json_decode($jsonString, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('资源方库存推送：JSON解析失败（经过清理后）', [
                'original' => $original,
                'cleaned' => $jsonString,
                'original_length' => strlen($original),
                'cleaned_length' => strlen($jsonString),
                'cleaned_preview' => substr($jsonString, 0, 200),
                'error' => json_last_error_msg(),
            ]);
            return null;
        }
        
        if (!is_array($result)) {
            Log::warning('资源方库存推送：JSON解析结果不是数组', [
                'original' => $original,
                'cleaned' => $jsonString,
                'parsed_type' => gettype($result),
                'parsed_value' => $result,
            ]);
            return null;
        }
        
        return $result;
    }

    /**
     * 同步处理方式（原有逻辑，保持向后兼容）
     * 
     * @param string $rawBody XML请求体
     * @param int $softwareProviderId 软件服务商ID，用于过滤酒店（避免不同服务商的酒店external_code冲突）
     */
    protected function handleHengdianInventorySync(string $rawBody, int $softwareProviderId): \Illuminate\Http\Response
    {
        // 解析XML请求
        $xmlObj = new SimpleXMLElement($rawBody);
        
        // 使用辅助方法解析JSON
        $roomQuotaMap = $this->parseJsonFromXml($xmlObj->RoomQuotaMap);
        
        if ($roomQuotaMap === null) {
            Log::error('资源方库存推送：JSON解析失败');
            return $this->xmlResponse('-1', '数据格式错误：JSON解析失败');
        }
        
        if (empty($roomQuotaMap)) {
            Log::warning('资源方库存推送：RoomQuotaMap为空');
            return $this->xmlResponse('0', '成功');
        }

        try {
            // 处理每个酒店的库存信息
            $successCount = 0;
            $failCount = 0;
            $errors = [];

            foreach ($roomQuotaMap as $hotelData) {
                $hotelNo = $hotelData['hotelNo'] ?? null;
                $roomType = $hotelData['roomType'] ?? null;
                $roomQuota = $hotelData['roomQuota'] ?? [];

                if (!$hotelNo || !$roomType) {
                    $failCount++;
                    $errors[] = "缺少hotelNo或roomType";
                    continue;
                }

                try {
                    // 查找酒店（优先使用external_code，否则使用code）
                    // 同时通过软件服务商过滤，避免不同服务商的酒店external_code冲突
                    $hotelQuery = Hotel::where(function($query) use ($hotelNo) {
                        $query->where('external_code', $hotelNo)
                              ->orWhere('code', $hotelNo);
                    });
                    
                    // 通过景区关联的软件服务商过滤
                    $hotelQuery->whereHas('scenicSpot', function($query) use ($softwareProviderId) {
                        // 支持一对一关系（旧字段）和多对多关系
                        $query->where(function($q) use ($softwareProviderId) {
                            $q->where('software_provider_id', $softwareProviderId)
                              ->orWhereHas('softwareProviders', function($subQuery) use ($softwareProviderId) {
                                  $subQuery->where('software_providers.id', $softwareProviderId);
                              });
                        });
                    });
                    
                    $hotel = $hotelQuery->first();

                    if (!$hotel) {
                        $failCount++;
                        $errors[] = "未找到酒店：{$hotelNo}（软件服务商ID：{$softwareProviderId}）";
                        Log::warning('资源方库存推送：未找到酒店', [
                            'hotel_no' => $hotelNo,
                            'software_provider_id' => $softwareProviderId,
                        ]);
                        continue;
                    }

                    // 查找房型（严格匹配：优先external_code，避免误匹配）
                    // 修复：当房型标识变更时（如"标准间"改为"豪华标准间"），避免通过name匹配到错误的房型
                    $roomTypeModel = null;

                    // 第一步：优先精确匹配 external_code
                    $roomTypeModel = RoomType::where('hotel_id', $hotel->id)
                        ->where('external_code', $roomType)
                        ->first();

                    // 第二步：如果未匹配到，检查是否有房型设置了 external_code
                    if (!$roomTypeModel) {
                        $hasAnyExternalCode = RoomType::where('hotel_id', $hotel->id)
                            ->whereNotNull('external_code')
                            ->where('external_code', '!=', '')
                            ->exists();
                        
                        if ($hasAnyExternalCode) {
                            // 如果酒店下有房型设置了 external_code，但当前推送的 roomType 未匹配到
                            // 说明可能是房型标识已变更，记录警告但不使用 name 匹配（避免错误匹配）
                            $failCount++;
                            $errorMsg = "未找到房型：{$roomType}（酒店：{$hotelNo}）。该酒店下存在设置了external_code的房型，但未匹配到对应的external_code，可能是房型标识已变更";
                            $errors[] = $errorMsg;
                            Log::warning('资源方库存推送：房型external_code未匹配，且酒店下存在设置了external_code的房型', [
                                'hotel_id' => $hotel->id,
                                'hotel_no' => $hotelNo,
                                'hotel_name' => $hotel->name ?? '',
                                'room_type' => $roomType,
                                'suggestion' => '请检查房型的external_code是否正确，或横店系统推送的roomType是否已变更。如果房型标识已变更，请更新对应房型的external_code',
                            ]);
                            continue;
                        } else {
                            // 如果酒店下所有房型都没有设置 external_code，使用 name 匹配（向后兼容）
                            $roomTypeModel = RoomType::where('hotel_id', $hotel->id)
                                ->where('name', $roomType)
                                ->first();
                            
                            if (!$roomTypeModel) {
                                $failCount++;
                                $errors[] = "未找到房型：{$roomType}（酒店：{$hotelNo}）";
                                Log::warning('资源方库存推送：未找到房型（通过name匹配也失败）', [
                                    'hotel_id' => $hotel->id,
                                    'hotel_no' => $hotelNo,
                                    'room_type' => $roomType,
                                ]);
                                continue;
                            }
                            
                            // 记录使用name匹配的日志（用于排查）
                            Log::info('资源方库存推送：使用name匹配房型（该酒店下所有房型都未设置external_code）', [
                                'hotel_id' => $hotel->id,
                                'hotel_no' => $hotelNo,
                                'room_type' => $roomType,
                                'matched_room_type_id' => $roomTypeModel->id,
                                'matched_room_type_name' => $roomTypeModel->name,
                            ]);
                        }
                    }

                    // Redis 指纹比对，找出变化的库存
                    $dirtyInventories = [];
                    $changedDates = [];

                    foreach ($roomQuota as $quotaData) {
                        $date = $quotaData['date'] ?? null;
                        $newQuota = (int)($quotaData['quota'] ?? 0);

                        if (!$date) {
                            continue;
                        }

                        // Redis 指纹比对
                        $fingerprintKey = "inventory:fingerprint:{$roomTypeModel->id}:{$date}";
                        
                        try {
                            $lastQuota = Redis::get($fingerprintKey);
                            
                            // 如果值相同，跳过（去重）
                            if ($lastQuota !== null && (int)$lastQuota === $newQuota) {
                                continue; // 库存未变化，丢弃
                            }

                            // 值不同或不存在，记录为脏数据
                            $dirtyInventories[] = [
                                'room_type_id' => $roomTypeModel->id,
                                'date' => $date,
                                'total_quantity' => $newQuota,
                                'available_quantity' => $newQuota,
                                'source' => PriceSource::API->value,
                                'is_closed' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $changedDates[] = $date;

                            // 更新 Redis 指纹（先更新，避免并发问题）
                            $fingerprintTtl = (int) env('INVENTORY_FINGERPRINT_TTL_DAYS', 30) * 86400; // 默认30天
                            Redis::setex($fingerprintKey, $fingerprintTtl, $newQuota);
                        } catch (ConnectionException $e) {
                            // Redis 故障降级：记录所有库存为脏数据
                            Log::warning('资源方库存推送：Redis 指纹比对失败，降级处理', [
                                'room_type_id' => $roomTypeModel->id,
                                'date' => $date,
                                'error' => $e->getMessage(),
                            ]);
                            $dirtyInventories[] = [
                                'room_type_id' => $roomTypeModel->id,
                                'date' => $date,
                                'total_quantity' => $newQuota,
                                'available_quantity' => $newQuota,
                                'source' => PriceSource::API->value,
                                'is_closed' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $changedDates[] = $date;
                        } catch (\Exception $e) {
                            // 其他异常也降级处理
                            Log::warning('资源方库存推送：Redis 指纹比对异常，降级处理', [
                                'room_type_id' => $roomTypeModel->id,
                                'date' => $date,
                                'error' => $e->getMessage(),
                            ]);
                            $dirtyInventories[] = [
                                'room_type_id' => $roomTypeModel->id,
                                'date' => $date,
                                'total_quantity' => $newQuota,
                                'available_quantity' => $newQuota,
                                'source' => PriceSource::API->value,
                                'is_closed' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                            $changedDates[] = $date;
                        }
                    }

                    // 批量更新数据库（只更新变化的库存）
                    if (!empty($dirtyInventories)) {
                        DB::beginTransaction();
                        try {
                            // 使用批量 upsert（高性能，不触发 Observer，但符合预期）
                            // 注意：更新字段列表中不包含 is_closed，确保不会覆盖手工关闭的库存状态
                            // 如果运营手工关闭了库存（is_closed = true），资源方推送时不会覆盖这个状态
                            // 这样推送到OTA时，is_closed = true 的库存会被正确处理为关闭状态（库存为0）
                            Inventory::upsert(
                                $dirtyInventories,
                                ['room_type_id', 'date'], // 唯一键
                                ['total_quantity', 'available_quantity', 'source', 'updated_at'] // 更新字段（不包含 is_closed）
                            );

                            DB::commit();
                            $successCount++;

                            // 记录同步日志
                            $scenicSpot = $hotel->scenicSpot ?? null;
                            if ($scenicSpot) {
                                ResourceSyncLog::create([
                                    'software_provider_id' => $scenicSpot->software_provider_id ?? null,
                                    'scenic_spot_id' => $scenicSpot->id,
                                    'sync_type' => 'inventory',
                                    'sync_mode' => 'push',
                                    'status' => 'success',
                                    'message' => "成功更新库存：酒店{$hotelNo}，房型{$roomType}，共" . count($dirtyInventories) . "条记录（变化）",
                                    'synced_count' => count($dirtyInventories),
                                    'last_synced_at' => now(),
                                ]);
                            }

                            // 触发推送到携程（如果启用自动推送）
                            if (!empty($changedDates) && env('ENABLE_AUTO_PUSH_INVENTORY_TO_OTA', true)) {
                                $this->triggerOtaPushForRoomType($roomTypeModel, array_unique($changedDates));
                            }

                        } catch (\Exception $e) {
                            DB::rollBack();
                            // 可选：回滚 Redis 指纹（但为了简单，这里不处理）
                            $failCount++;
                            $errors[] = "更新库存失败（酒店：{$hotelNo}，房型：{$roomType}）：" . $e->getMessage();
                            Log::error('资源方库存推送：更新库存失败', [
                                'hotel_id' => $hotel->id,
                                'room_type_id' => $roomTypeModel->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } else {
                        // 没有变化的库存，只记录日志
                        Log::info('资源方库存推送：库存未变化，跳过更新', [
                            'hotel_id' => $hotel->id,
                            'room_type_id' => $roomTypeModel->id,
                            'total_records' => count($roomQuota),
                        ]);
                        $successCount++;
                    }

                } catch (\Exception $e) {
                    $failCount++;
                    $errors[] = "处理失败（酒店：{$hotelNo}，房型：{$roomType}）：" . $e->getMessage();
                    Log::error('资源方库存推送：处理失败', [
                        'hotel_no' => $hotelNo,
                        'room_type' => $roomType,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($failCount > 0) {
                Log::warning('资源方库存推送：部分失败', [
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'errors' => $errors,
                ]);
            }

            return $this->xmlResponse('0', '成功');

        } catch (\Exception $e) {
            Log::error('资源方库存推送：同步处理异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->xmlResponse('-1', '处理异常：' . $e->getMessage());
        }
    }

    /**
     * 触发推送到OTA平台（针对特定房型和日期）
     * 推送到所有已推送的平台（包括携程和美团）
     * 
     * @param RoomType $roomType 房型
     * @param array $dates 变化的日期数组
     */
    protected function triggerOtaPushForRoomType(RoomType $roomType, array $dates): void
    {
        try {
            // 推送到所有已推送的平台（传入 null 表示推送到所有平台）
            // 放入队列（延迟合并，避免频繁推送）
            $pushDelay = (int) env('INVENTORY_PUSH_DELAY_SECONDS', 5);
            PushChangedInventoryToOtaJob::dispatch(
                $roomType->id,
                $dates,
                null // null 表示推送到所有已推送的平台（包括携程和美团）
            )->onQueue('ota-push')->delay(now()->addSeconds($pushDelay));

            Log::info('资源方库存推送：已触发OTA推送任务（推送到所有平台）', [
                'room_type_id' => $roomType->id,
                'dates_count' => count($dates),
                'dates' => array_slice($dates, 0, 10), // 只记录前10个日期
                'delay_seconds' => $pushDelay,
            ]);
        } catch (\Exception $e) {
            Log::error('资源方库存推送：触发OTA推送失败', [
                'room_type_id' => $roomType->id,
                'dates' => $dates,
                'error' => $e->getMessage(),
            ]);
            // 不抛出异常，避免影响主流程
        }
    }

    /**
     * 接收资源方推送的订单核销状态
     * 
     * 路由格式：/webhooks/resource/{api_type}/order-verification
     * 通过路由参数 {api_type} 识别软件服务商（如：hengdian）
     * 
     * @param Request $request
     * @param string $apiType 软件服务商的api_type（从路由参数获取）
     * @return JsonResponse
     */
    public function handleOrderVerification(Request $request, string $apiType): JsonResponse
    {
        try {
            $rawBody = $request->getContent();
            $headers = $request->headers->all();
            
            Log::info('资源方订单核销状态推送', [
                'api_type' => $apiType,
                'body' => $rawBody,
                'headers' => $headers,
            ]);

            // 根据api_type查找软件服务商
            $softwareProvider = SoftwareProvider::where('api_type', $apiType)->first();
            
            if (!$softwareProvider) {
                Log::error('资源方订单核销状态推送：未找到软件服务商', [
                    'api_type' => $apiType,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => '未找到软件服务商：' . $apiType,
                ], 404);
            }

            // 根据不同的服务商类型，调用对应的处理方法
            $handlerMethod = 'handle' . ucfirst($apiType) . 'OrderVerification';
            
            if (method_exists($this, $handlerMethod)) {
                return $this->$handlerMethod($request, $softwareProvider);
            }

            // 如果没有特定的处理方法，使用通用处理方法
            return $this->handleGenericOrderVerification($request, $softwareProvider);
            
        } catch (\Exception $e) {
            Log::error('资源方订单核销状态推送：处理异常', [
                'api_type' => $apiType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '处理异常：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 处理横店系统的订单核销状态推送
     * 
     * @param Request $request
     * @param SoftwareProvider $softwareProvider
     * @return JsonResponse
     */
    protected function handleHengdianOrderVerification(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        try {
            $rawBody = $request->getContent();
            
            // 解析XML请求
            $xmlObj = new SimpleXMLElement($rawBody);
            
            // 提取订单号和状态（根据横店系统的实际推送格式调整）
            $otaOrderNo = (string)($xmlObj->OtaOrderId ?? '');
            $status = (string)($xmlObj->Status ?? '');
            
            if (empty($otaOrderNo)) {
                Log::error('横店订单核销推送：订单号为空', [
                    'body' => $rawBody,
                ]);
                return $this->xmlResponse('-1', '订单号不能为空');
            }

            // 查找订单
            $order = \App\Models\Order::where('ota_order_no', $otaOrderNo)->first();
            
            if (!$order) {
                Log::warning('横店订单核销推送：订单不存在', [
                    'ota_order_no' => $otaOrderNo,
                ]);
                return $this->xmlResponse('-1', '订单不存在');
            }

            // 只有状态为'5'（已使用/已核销）时才处理
            if ($status !== '5') {
                Log::info('横店订单核销推送：订单状态不是已核销，跳过处理', [
                    'ota_order_no' => $otaOrderNo,
                    'status' => $status,
                ]);
                return $this->xmlResponse('0', '订单状态不是已核销');
            }

            // 构建核销数据
            $verificationData = [
                'status' => \App\Enums\OrderStatus::VERIFIED->value,
                'verified_at' => isset($xmlObj->VerifiedTime) ? (string)$xmlObj->VerifiedTime : null,
                'use_start_date' => isset($xmlObj->UseStartDate) ? (string)$xmlObj->UseStartDate : null,
                'use_end_date' => isset($xmlObj->UseEndDate) ? (string)$xmlObj->UseEndDate : null,
                'use_quantity' => isset($xmlObj->UseQuantity) ? (int)$xmlObj->UseQuantity : null,
                'passengers' => [],
                'vouchers' => [],
            ];

            // 异步处理订单核销状态（避免阻塞webhook响应）
            \App\Jobs\ProcessOrderVerificationJob::dispatch($order->id, $verificationData, 'webhook')
                ->onQueue('order-verification');

            Log::info('横店订单核销推送：已接收并放入队列', [
                'ota_order_no' => $otaOrderNo,
                'order_id' => $order->id,
            ]);

            return $this->xmlResponse('0', '已接收');
            
        } catch (\Exception $e) {
            Log::error('横店订单核销推送：处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->xmlResponse('-1', '处理失败：' . $e->getMessage());
        }
    }

    /**
     * 通用订单核销状态推送处理（适用于其他服务商）
     * 
     * @param Request $request
     * @param SoftwareProvider $softwareProvider
     * @return JsonResponse
     */
    protected function handleGenericOrderVerification(Request $request, SoftwareProvider $softwareProvider): JsonResponse
    {
        try {
            // 尝试解析JSON格式的请求
            $data = $request->json()->all();
            
            $otaOrderNo = $data['order_no'] ?? $data['ota_order_no'] ?? $data['orderId'] ?? '';
            $status = $data['status'] ?? '';
            
            if (empty($otaOrderNo)) {
                Log::error('通用订单核销推送：订单号为空', [
                    'data' => $data,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => '订单号不能为空',
                ], 400);
            }

            // 查找订单
            $order = \App\Models\Order::where('ota_order_no', $otaOrderNo)->first();
            
            if (!$order) {
                Log::warning('通用订单核销推送：订单不存在', [
                    'ota_order_no' => $otaOrderNo,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => '订单不存在',
                ], 404);
            }

            // 构建核销数据
            $verificationData = [
                'status' => $status === 'verified' || $status === '5' ? \App\Enums\OrderStatus::VERIFIED->value : $status,
                'verified_at' => $data['verified_at'] ?? null,
                'use_start_date' => $data['use_start_date'] ?? null,
                'use_end_date' => $data['use_end_date'] ?? null,
                'use_quantity' => $data['use_quantity'] ?? null,
                'passengers' => $data['passengers'] ?? [],
                'vouchers' => $data['vouchers'] ?? [],
            ];

            // 异步处理订单核销状态
            \App\Jobs\ProcessOrderVerificationJob::dispatch($order->id, $verificationData, 'webhook')
                ->onQueue('order-verification');

            Log::info('通用订单核销推送：已接收并放入队列', [
                'api_type' => $softwareProvider->api_type,
                'ota_order_no' => $otaOrderNo,
                'order_id' => $order->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => '已接收',
            ]);
            
        } catch (\Exception $e) {
            Log::error('通用订单核销推送：处理失败', [
                'api_type' => $softwareProvider->api_type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => '处理失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 返回XML响应
     */
    protected function xmlResponse(string $resultCode, string $message): \Illuminate\Http\Response
    {
        $xml = new SimpleXMLElement('<Result></Result>');
        $xml->addChild('ResultCode', $resultCode);
        $xml->addChild('Message', $message);

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }
}
