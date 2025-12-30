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
    public function handleHengdianInventory(Request $request): JsonResponse
    {
        try {
            $rawBody = $request->getContent();
            
            Log::info('资源方库存推送', [
                'body' => $rawBody,
            ]);

            // 尝试识别景区（用于日志记录和后续可能的验证）
            $identificationResult = null;
            try {
                // 解析回调数据，提取业务标识
                $xmlObj = new SimpleXMLElement($rawBody);
                $roomQuotaMapJson = (string)$xmlObj->RoomQuotaMap;
                $roomQuotaMap = json_decode($roomQuotaMapJson, true);
                
                // 从第一个酒店数据中提取 hotelNo
                $callbackData = [];
                if (!empty($roomQuotaMap) && isset($roomQuotaMap[0]['hotelNo'])) {
                    $callbackData['hotelNo'] = $roomQuotaMap[0]['hotelNo'];
                }
                
                // 获取软件服务商ID（横店系统）
                $softwareProviderId = SoftwareProvider::where('api_type', 'hengdian')->value('id');
                
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
                // 使用新的异步处理方式
                return $this->handleHengdianInventoryAsync($rawBody);
            }

            // 使用原有的同步处理方式（保持向后兼容）
            return $this->handleHengdianInventorySync($rawBody);

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
     */
    protected function handleHengdianInventoryAsync(string $rawBody): JsonResponse
    {
        try {
            // 将数据放入队列，立即返回响应给景区方
            \App\Jobs\ProcessResourceInventoryPushJob::dispatch($rawBody)
                ->onQueue('resource-push'); // 使用专门的队列
            
            Log::info('资源方库存推送：已接收并放入队列（异步处理）', [
                'body_length' => strlen($rawBody),
            ]);
            
            // 立即返回，不等待处理完成
            return $this->xmlResponse('0', '已接收');
            
        } catch (\Exception $e) {
            Log::error('资源方库存推送：异步处理失败', [
                'error' => $e->getMessage(),
            ]);
            // 降级到同步处理
            return $this->handleHengdianInventorySync($rawBody);
        }
    }

    /**
     * 同步处理方式（原有逻辑，保持向后兼容）
     */
    protected function handleHengdianInventorySync(string $rawBody): JsonResponse
    {
        // 解析XML请求
        $xmlObj = new SimpleXMLElement($rawBody);
        $roomQuotaMapJson = (string)$xmlObj->RoomQuotaMap;

        if (empty($roomQuotaMapJson)) {
            Log::warning('资源方库存推送：RoomQuotaMap为空');
            return $this->xmlResponse('0', '成功');
        }

        // 解析JSON字符串
        $roomQuotaMap = json_decode($roomQuotaMapJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('资源方库存推送：JSON解析失败', [
                'json' => $roomQuotaMapJson,
                'error' => json_last_error_msg(),
            ]);
            return $this->xmlResponse('-1', 'JSON解析失败');
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
                    $hotel = Hotel::where('external_code', $hotelNo)
                        ->orWhere('code', $hotelNo)
                        ->first();

                    if (!$hotel) {
                        $failCount++;
                        $errors[] = "未找到酒店：{$hotelNo}";
                        Log::warning('资源方库存推送：未找到酒店', ['hotel_no' => $hotelNo]);
                        continue;
                    }

                    // 查找房型（优先使用external_code，否则使用name）
                    $roomTypeModel = RoomType::where('hotel_id', $hotel->id)
                        ->where(function($query) use ($roomType) {
                            $query->where('external_code', $roomType)
                                  ->orWhere('name', $roomType);
                        })
                        ->first();

                    if (!$roomTypeModel) {
                        $failCount++;
                        $errors[] = "未找到房型：{$roomType}（酒店：{$hotelNo}）";
                        Log::warning('资源方库存推送：未找到房型', [
                            'hotel_id' => $hotel->id,
                            'room_type' => $roomType,
                        ]);
                        continue;
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
                            Inventory::upsert(
                                $dirtyInventories,
                                ['room_type_id', 'date'], // 唯一键
                                ['total_quantity', 'available_quantity', 'source', 'updated_at'] // 更新字段
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
     * 触发推送到携程（针对特定房型和日期）
     * 
     * @param RoomType $roomType 房型
     * @param array $dates 变化的日期数组
     */
    protected function triggerOtaPushForRoomType(RoomType $roomType, array $dates): void
    {
        try {
            // 查找携程平台
            $ctripPlatform = OtaPlatform::where('code', OtaPlatformEnum::CTRIP->value)->first();
            if (!$ctripPlatform) {
                Log::warning('资源方库存推送：携程平台不存在', [
                    'room_type_id' => $roomType->id,
                ]);
                return;
            }

            // 放入队列（延迟合并，避免频繁推送）
            $pushDelay = (int) env('INVENTORY_PUSH_DELAY_SECONDS', 5);
            PushChangedInventoryToOtaJob::dispatch(
                $roomType->id,
                $dates,
                $ctripPlatform->id
            )->onQueue('ota-push')->delay(now()->addSeconds($pushDelay));

            Log::info('资源方库存推送：已触发OTA推送任务', [
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
     * 返回XML响应
     */
    protected function xmlResponse(string $resultCode, string $message): JsonResponse
    {
        $xml = new SimpleXMLElement('<Result></Result>');
        $xml->addChild('ResultCode', $resultCode);
        $xml->addChild('Message', $message);

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }
}
