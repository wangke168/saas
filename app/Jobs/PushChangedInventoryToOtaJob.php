<?php

namespace App\Jobs;

use App\Models\RoomType;
use App\Models\Product;
use App\Models\OtaPlatform;
use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Services\OTA\CtripService;
use App\Services\OTA\MeituanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 推送变化的库存到OTA平台
 * 
 * 统一队列任务，用于处理库存变化后的自动推送到OTA平台
 * 支持增量推送（只推送变化的日期）
 */
class PushChangedInventoryToOtaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     * 
     * @param int $roomTypeId 房型ID
     * @param array $dates 需要推送的日期数组，格式：['2025-12-27', '2025-12-28']
     * @param int|null $otaPlatformId OTA平台ID，如果为null则推送到所有已推送的平台（默认携程）
     */
    public function __construct(
        public int $roomTypeId,
        public array $dates,
        public ?int $otaPlatformId = null
    ) {
        $this->onQueue('ota-push');
    }

    /**
     * Execute the job.
     */
    public function handle(CtripService $ctripService, MeituanService $meituanService): void
    {
        Log::info('PushChangedInventoryToOtaJob 开始执行', [
            'room_type_id' => $this->roomTypeId,
            'dates' => $this->dates,
            'dates_count' => count($this->dates),
            'ota_platform_id' => $this->otaPlatformId,
        ]);

        try {
            // 加载房型
            $roomType = RoomType::with('hotel')->find($this->roomTypeId);
            if (!$roomType) {
                Log::warning('推送库存变化：房型不存在', [
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                Log::warning('推送库存变化：酒店不存在', [
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            // 查找关联该房型的所有产品
            $products = Product::whereHas('prices', function ($q) {
                $q->where('room_type_id', $this->roomTypeId);
            })
            ->where('is_active', true)
            ->get();

            if ($products->isEmpty()) {
                Log::info('推送库存变化：没有关联的产品', [
                    'room_type_id' => $this->roomTypeId,
                ]);
                return;
            }

            // 确定要推送的OTA平台
            $otaPlatforms = [];
            if ($this->otaPlatformId) {
                // 如果指定了平台ID，只推送到该平台
                $platform = OtaPlatform::find($this->otaPlatformId);
                if ($platform) {
                    $otaPlatforms[] = $platform;
                }
            } else {
                // 如果没有指定平台ID，推送到所有已推送的平台（包括携程和美团）
                // 查找所有已推送产品的平台
                $platforms = OtaPlatform::whereIn('code', [
                    OtaPlatformEnum::CTRIP->value,
                    OtaPlatformEnum::MEITUAN->value,
                ])->get();
                
                foreach ($platforms as $platform) {
                    // 检查是否有产品已推送到该平台
                    $hasOtaProduct = $products->contains(function ($product) use ($platform) {
                        return $product->otaProducts()
                            ->where('ota_platform_id', $platform->id)
                            ->where('is_active', true)
                            ->exists();
                    });
                    
                    if ($hasOtaProduct) {
                        $otaPlatforms[] = $platform;
                    }
                }
                
                // 如果没有找到任何平台，记录警告但不报错（向后兼容）
                if (empty($otaPlatforms)) {
                    Log::info('推送库存变化：没有找到已推送的平台', [
                        'room_type_id' => $this->roomTypeId,
                    ]);
                }
            }

            if (empty($otaPlatforms)) {
                Log::warning('推送库存变化：未找到OTA平台', [
                    'ota_platform_id' => $this->otaPlatformId,
                ]);
                return;
            }

            // 对每个产品和OTA平台组合进行推送
            foreach ($products as $product) {
                // 检查产品编码
                if (empty($product->code)) {
                    Log::warning('推送库存变化：产品编码为空', [
                        'product_id' => $product->id,
                        'room_type_id' => $this->roomTypeId,
                    ]);
                    continue;
                }

                // 检查酒店和房型编码
                if (empty($hotel->code) || empty($roomType->code)) {
                    Log::warning('推送库存变化：酒店或房型编码为空', [
                        'product_id' => $product->id,
                        'hotel_id' => $hotel->id,
                        'room_type_id' => $roomType->id,
                        'hotel_code' => $hotel->code,
                        'room_type_code' => $roomType->code,
                    ]);
                    continue;
                }

                foreach ($otaPlatforms as $platform) {
                    // 检查产品是否已推送到该OTA平台
                    $otaProduct = $product->otaProducts()
                        ->where('ota_platform_id', $platform->id)
                        ->where('is_active', true)
                        ->first();

                    if (!$otaProduct) {
                        Log::info('推送库存变化：产品未推送到该OTA平台', [
                            'product_id' => $product->id,
                            'ota_platform_id' => $platform->id,
                            'platform_code' => $platform->code->value,
                        ]);
                        continue;
                    }

                    // 根据平台类型推送
                    match ($platform->code->value) {
                        'ctrip' => $this->pushToCtrip($product, $hotel, $roomType, $ctripService),
                        'meituan' => $this->pushToMeituan($product, $hotel, $roomType, $meituanService),
                        default => Log::info('推送库存变化：暂不支持该OTA平台', [
                            'product_id' => $product->id,
                            'platform_code' => $platform->code->value,
                        ]),
                    };
                }
            }

        } catch (\Exception $e) {
            Log::error('推送库存变化异常', [
                'room_type_id' => $this->roomTypeId,
                'dates' => $this->dates,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 推送到携程
     */
    protected function pushToCtrip(
        Product $product,
        \App\Models\Hotel $hotel,
        RoomType $roomType,
        CtripService $ctripService
    ): void {
        try {
            // 根据产品的入住天数，扩大查询日期范围
            // 如果产品有入住天数（stay_days > 1），需要查询所有相关日期，以便正确计算连续入住天数的库存
            // 
            // 逻辑说明：
            // - 如果 stay_days = 2，变化日期是 2026-01-11
            // - 需要查询：2026-01-10, 2026-01-11, 2026-01-12
            // - 因为：
            //   1. 从 2026-01-10 开始入住需要：2026-01-10, 2026-01-11
            //      - 如果 2026-01-11 变成0，2026-01-10 也应该变成0（无法满足连续入住）
            //      - 如果 2026-01-11 从0变成正数，2026-01-10 需要重新计算并推送准确库存（可以满足连续入住）
            //   2. 从 2026-01-11 开始入住需要：2026-01-11, 2026-01-12
            $stayDays = $product->stay_days ?: 1;
            // 优化：过滤掉已过去的日期
            $queryDates = $this->filterFutureDates($this->dates);
            
            if (empty($queryDates)) {
                Log::info('推送库存变化到携程：所有日期都已过去，跳过推送', [
                    'product_id' => $product->id,
                    'original_dates' => $this->dates,
                ]);
                return;
            }
            
            if ($stayDays > 1 && !empty($queryDates)) {
                // 对于每个变化的日期，需要查询前面相关日期的库存
                // 这样当库存变化时，可以重新计算所有受影响日期的库存
                $expandedDates = [];
                foreach ($queryDates as $date) {
                    $dateObj = \Carbon\Carbon::parse($date);
                    
                    // 查询范围：[变化日期 - (stay_days-1), 变化日期]
                    // 例如：stay_days=2, 变化日期=2026-01-11
                    // 查询：2026-01-10, 2026-01-11
                    // 
                    // 原因：
                    // - 如果 2026-01-11 的库存变成0，会影响从 2026-01-10 开始的连续入住
                    // - 但不会影响从 2026-01-12 开始的连续入住（2026-01-12 可以开始新的连续入住）
                    // - 因此只需要查询前面和当前日期，不需要查询后面日期
                    for ($i = -($stayDays - 1); $i <= 0; $i++) {
                        $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                        if (!in_array($checkDate, $expandedDates)) {
                            $expandedDates[] = $checkDate;
                        }
                    }
                }
                
                // 排序日期，确保顺序正确
                sort($expandedDates);
                // 再次过滤掉已过去的日期（扩大范围后可能包含过去的日期）
                $queryDates = $this->filterFutureDates($expandedDates);
                
                if (empty($queryDates)) {
                    Log::info('推送库存变化到携程：扩大日期范围后所有日期都已过去，跳过推送', [
                        'product_id' => $product->id,
                        'stay_days' => $stayDays,
                        'original_dates' => $this->dates,
                        'expanded_dates' => $expandedDates,
                    ]);
                    return;
                }
                
                Log::debug('推送库存变化：扩大查询日期范围（考虑入住天数）', [
                    'product_id' => $product->id,
                    'stay_days' => $stayDays,
                    'original_dates' => $this->dates,
                    'expanded_dates' => $queryDates,
                    'reason' => '只查询前面和当前日期，不查询后面日期。后面日期的连续入住不受前面日期变化影响',
                ]);
            }
            
            // 推送到携程（使用扩大后的日期范围）
            $result = $ctripService->syncProductStockByCombo(
                $product,
                $hotel,
                $roomType,
                $queryDates,
                'DATE_REQUIRED'
            );

            $resultCode = $result['header']['resultCode'] ?? null;
            $resultMessage = $result['header']['resultMessage'] ?? null;

            if ($resultCode === '0000') {
                Log::info('库存变化自动推送到携程成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'original_dates' => $this->dates,
                    'query_dates' => $queryDates,
                    'stay_days' => $stayDays,
                    'dates_count' => count($queryDates),
                ]);
            } else {
                Log::warning('库存变化自动推送到携程失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'original_dates' => $this->dates,
                    'query_dates' => $queryDates,
                    'stay_days' => $stayDays,
                    'result_code' => $resultCode,
                    'result_message' => $resultMessage,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('库存变化自动推送到携程异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'original_dates' => $this->dates,
                'query_dates' => $queryDates ?? null,
                'stay_days' => $stayDays ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 推送到美团（增量推送）
     */
    protected function pushToMeituan(
        Product $product,
        \App\Models\Hotel $hotel,
        RoomType $roomType,
        MeituanService $meituanService
    ): void {
        try {
            // 根据产品的入住天数，扩大查询日期范围
            // 如果产品有入住天数（stay_days > 1），需要查询所有相关日期，以便正确计算连续入住天数的库存
            // 
            // 逻辑说明：
            // - 如果 stay_days = 2，变化日期是 2026-01-11
            // - 需要查询：2026-01-10, 2026-01-11
            // - 因为：
            //   1. 从 2026-01-10 开始入住需要：2026-01-10, 2026-01-11
            //      - 如果 2026-01-11 变成0，2026-01-10 也应该变成0（无法满足连续入住）
            //      - 如果 2026-01-11 从0变成正数，2026-01-10 需要重新计算并推送准确库存（可以满足连续入住）
            //   2. 从 2026-01-11 开始入住需要：2026-01-11, 2026-01-12
            $stayDays = $product->stay_days ?: 1;
            // 优化：过滤掉已过去的日期
            $queryDates = $this->filterFutureDates($this->dates);
            
            if (empty($queryDates)) {
                Log::info('推送库存变化到美团：所有日期都已过去，跳过推送', [
                    'product_id' => $product->id,
                    'original_dates' => $this->dates,
                ]);
                return;
            }
            
            if ($stayDays > 1 && !empty($queryDates)) {
                // 对于每个变化的日期，需要查询前面相关日期的库存
                // 这样当库存变化时，可以重新计算所有受影响日期的库存
                $expandedDates = [];
                foreach ($queryDates as $date) {
                    $dateObj = \Carbon\Carbon::parse($date);
                    
                    // 查询范围：[变化日期 - (stay_days-1), 变化日期]
                    // 例如：stay_days=2, 变化日期=2026-01-11
                    // 查询：2026-01-10, 2026-01-11
                    // 
                    // 原因：
                    // - 如果 2026-01-11 的库存变成0，会影响从 2026-01-10 开始的连续入住
                    // - 但不会影响从 2026-01-12 开始的连续入住（2026-01-12 可以开始新的连续入住）
                    // - 因此只需要查询前面和当前日期，不需要查询后面日期
                    for ($i = -($stayDays - 1); $i <= 0; $i++) {
                        $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                        if (!in_array($checkDate, $expandedDates)) {
                            $expandedDates[] = $checkDate;
                        }
                    }
                }
                
                // 排序日期，确保顺序正确
                sort($expandedDates);
                // 再次过滤掉已过去的日期（扩大范围后可能包含过去的日期）
                $queryDates = $this->filterFutureDates($expandedDates);
                
                if (empty($queryDates)) {
                    Log::info('推送库存变化到美团：扩大日期范围后所有日期都已过去，跳过推送', [
                        'product_id' => $product->id,
                        'stay_days' => $stayDays,
                        'original_dates' => $this->dates,
                        'expanded_dates' => $expandedDates,
                    ]);
                    return;
                }
                
                Log::debug('推送库存变化到美团：扩大查询日期范围（考虑入住天数）', [
                    'product_id' => $product->id,
                    'stay_days' => $stayDays,
                    'original_dates' => $this->dates,
                    'expanded_dates' => $queryDates,
                    'reason' => '只查询前面和当前日期，不查询后面日期。后面日期的连续入住不受前面日期变化影响',
                ]);
            }
            
            // 推送到美团（使用扩大后的日期范围，增量推送）
            $result = $meituanService->syncLevelPriceStockByDates(
                $product,
                $hotel,
                $roomType,
                $queryDates
            );

            if ($result['success'] ?? false) {
                Log::info('库存变化自动推送到美团成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'original_dates' => $this->dates,
                    'query_dates' => $queryDates,
                    'stay_days' => $stayDays,
                    'dates_count' => count($queryDates),
                ]);
            } else {
                Log::warning('库存变化自动推送到美团失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'original_dates' => $this->dates,
                    'query_dates' => $queryDates,
                    'stay_days' => $stayDays,
                    'result' => $result,
                    'error_message' => $result['message'] ?? '未知错误',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('库存变化自动推送到美团异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'original_dates' => $this->dates,
                'query_dates' => $queryDates ?? null,
                'stay_days' => $stayDays ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // 美团推送失败不影响其他平台，只记录错误，不抛出异常
        }
    }

    /**
     * 过滤掉已过去的日期，只保留今天及未来的日期
     * 
     * @param array $dates 日期数组，格式：['2025-01-01', '2025-01-02', ...]
     * @return array 过滤后的日期数组
     */
    protected function filterFutureDates(array $dates): array
    {
        if (empty($dates)) {
            return [];
        }

        $today = \Carbon\Carbon::today()->format('Y-m-d');
        $futureDates = [];

        foreach ($dates as $date) {
            // 只保留今天及未来的日期
            if ($date >= $today) {
                $futureDates[] = $date;
            }
        }

        // 去重并排序
        $futureDates = array_unique($futureDates);
        sort($futureDates);

        return $futureDates;
    }
}

