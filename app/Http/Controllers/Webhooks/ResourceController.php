<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Inventory;
use App\Models\ResourceSyncLog;
use App\Enums\PriceSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

                    // 更新库存
                    DB::beginTransaction();
                    try {
                        foreach ($roomQuota as $quotaData) {
                            $date = $quotaData['date'] ?? null;
                            $quota = (int)($quotaData['quota'] ?? 0);

                            if (!$date) {
                                continue;
                            }

                            // 查找或创建库存记录
                            $inventory = Inventory::firstOrNew([
                                'room_type_id' => $roomTypeModel->id,
                                'date' => $date,
                            ]);

                            // 更新可用库存（总库存 = 可用库存）
                            $inventory->total_quantity = $quota;
                            $inventory->available_quantity = $quota;
                            $inventory->source = PriceSource::API; // 标记为接口推送
                            $inventory->save();
                        }

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
                                'message' => "成功更新库存：酒店{$hotelNo}，房型{$roomType}，共" . count($roomQuota) . "条记录",
                                'synced_count' => count($roomQuota),
                                'last_synced_at' => now(),
                            ]);
                        }

                    } catch (\Exception $e) {
                        DB::rollBack();
                        $failCount++;
                        $errors[] = "更新库存失败（酒店：{$hotelNo}，房型：{$roomType}）：" . $e->getMessage();
                        Log::error('资源方库存推送：更新库存失败', [
                            'hotel_id' => $hotel->id,
                            'room_type_id' => $roomTypeModel->id,
                            'error' => $e->getMessage(),
                        ]);
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

