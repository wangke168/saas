<?php

namespace App\Services;

use App\Enums\SystemPkgExceptionOrderType;
use App\Enums\SystemPkgExceptionOrderStatus;
use App\Enums\SystemPkgOrderItemStatus;
use App\Enums\SystemPkgOrderStatus;
use App\Models\ProductBundleItem;
use App\Models\ResHotel;
use App\Models\ResHotelDailyStock;
use App\Models\ResRoomType;
use App\Models\ResourceConfig;
use App\Models\SystemPkgExceptionOrder;
use App\Models\SystemPkgOrder;
use App\Models\SystemPkgOrderItem;
use App\Models\Ticket;
use App\Services\Resource\ResourceServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 订单拆单处理服务
 * 支持乐观锁、重试机制、异常订单处理
 */
class OrderSplitterService
{
    /**
     * 拆单并处理
     */
    public function splitAndProcess(SystemPkgOrder $order): array
    {
        DB::beginTransaction();
        try {
            // 1. 加载产品清单
            $bundleItems = $order->salesProduct->bundleItems;
            
            // 2. 创建子订单记录
            $orderItems = [];
            foreach ($bundleItems as $item) {
                $orderItem = SystemPkgOrderItem::create([
                    'order_id' => $order->id,
                    'item_type' => $item->resource_type,
                    'resource_id' => $item->resource_id,
                    'resource_name' => $this->getResourceName($item),
                    'quantity' => $item->quantity,
                    'status' => SystemPkgOrderItemStatus::PENDING->value,
                    'max_retries' => 3, // 最大重试3次
                ]);
                $orderItems[] = $orderItem;
            }
            
            DB::commit();
            
            // 3. 异步处理拆单（避免长时间事务）
            \App\Jobs\ProcessSplitOrderJob::dispatch($order->id);
            
            return ['success' => true, 'order_items' => $orderItems];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('OrderSplitterService: 拆单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 处理拆单任务（队列任务）
     */
    public function processSplitOrder(SystemPkgOrder $order): void
    {
        $orderItems = $order->orderItems;
        
        // 分组处理
        $ticketItems = $orderItems->where('item_type', 'TICKET');
        $hotelItems = $orderItems->where('item_type', 'HOTEL');
        
        $results = [];
        
        // 1. 处理门票订单（并发）
        foreach ($ticketItems as $item) {
            try {
                $result = $this->processTicketOrder($order, $item);
                $results[] = $result;
            } catch (\Exception $e) {
                $item->update([
                    'status' => SystemPkgOrderItemStatus::FAILED->value,
                    'error_message' => $e->getMessage(),
                ]);
                $results[] = ['success' => false, 'item_id' => $item->id];
                Log::error('OrderSplitterService: 门票订单处理失败', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // 2. 处理酒店订单（并发，支持重试）
        foreach ($hotelItems as $item) {
            try {
                $result = $this->processHotelOrderWithRetry($order, $item);
                $results[] = $result;
            } catch (\Exception $e) {
                $item->update([
                    'status' => SystemPkgOrderItemStatus::FAILED->value,
                    'error_message' => $e->getMessage(),
                ]);
                $results[] = ['success' => false, 'item_id' => $item->id];
                Log::error('OrderSplitterService: 酒店订单处理失败', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // 3. 检查处理结果
        $allSuccess = collect($results)->every(fn($r) => $r['success'] ?? false);
        
        if ($allSuccess) {
            // 全部成功，更新主订单状态
            $order->update([
                'status' => SystemPkgOrderStatus::CONFIRMED->value,
                'confirmed_at' => now(),
            ]);
            Log::info('OrderSplitterService: 拆单处理全部成功', [
                'order_id' => $order->id,
            ]);
        } else {
            // 部分失败，创建异常订单（人工处理）
            $this->createExceptionOrder($order, $results);
        }
    }
    
    /**
     * 处理门票订单
     */
    private function processTicketOrder(SystemPkgOrder $order, SystemPkgOrderItem $item): array
    {
        $ticket = Ticket::find($item->resource_id);
        if (!$ticket) {
            throw new \Exception("门票不存在：ID={$item->resource_id}");
        }
        
        // 调用景区接口下单（根据ticket关联的software_provider）
        $softwareProvider = $ticket->softwareProvider;
        if (!$softwareProvider) {
            throw new \Exception("门票未配置软件服务商：ID={$ticket->id}");
        }
        
        $scenicSpot = $ticket->scenicSpot;
        if (!$scenicSpot) {
            throw new \Exception("门票未关联景区：ID={$ticket->id}");
        }
        
        // 获取资源配置
        $config = ResourceConfig::with('softwareProvider')
            ->where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();
        
        if (!$config) {
            throw new \Exception("景区未配置该服务商的参数：scenic_spot_id={$scenicSpot->id}, software_provider_id={$softwareProvider->id}");
        }
        
        // 验证订单是否系统直连
        if (!$config->isOrderAuto()) {
            throw new \Exception("该门票订单处理方式不是系统直连，无法自动处理");
        }
        
        // 根据软件服务商类型创建服务
        $resourceService = match($softwareProvider->api_type) {
            'hengdian' => app(\App\Services\Resource\HengdianService::class)->setConfig($config),
            default => null,
        };
        
        if (!$resourceService) {
            throw new \Exception("不支持的软件服务商类型：{$softwareProvider->api_type}");
        }
        
        // 调用资源方接口下单（门票数量需要乘以2，因为是双人门票）
        // 注意：这里需要根据实际API接口调整参数
        $result = $resourceService->confirmOrder($this->buildTicketOrderData($order, $ticket, $item));
        
        if ($result['success']) {
            $item->update([
                'status' => SystemPkgOrderItemStatus::SUCCESS->value,
                'resource_order_no' => $result['data']['order_no'] ?? $result['data']['confirm_no'] ?? '',
                'processed_at' => now(),
            ]);
            return ['success' => true, 'item_id' => $item->id];
        } else {
            throw new \Exception($result['message'] ?? '门票下单失败');
        }
    }
    
    /**
     * 构建门票订单数据
     */
    private function buildTicketOrderData(SystemPkgOrder $order, Ticket $ticket, SystemPkgOrderItem $item): array
    {
        // 这里需要根据实际API接口调整
        // 暂时返回一个模拟的订单对象，实际使用时需要创建临时Order对象或调整接口
        return [
            'ticket_id' => $ticket->id,
            'quantity' => $item->quantity * 2, // 双人门票
            'use_date' => $order->check_in_date->format('Y-m-d'),
            'order_no' => $order->order_no,
        ];
    }
    
    /**
     * 处理酒店订单（使用软件服务商关联模式，与门票保持一致）
     */
    private function processHotelOrder(SystemPkgOrder $order, SystemPkgOrderItem $item): array
    {
        $roomType = ResRoomType::find($item->resource_id);
        if (!$roomType) {
            throw new \Exception("房型不存在：ID={$item->resource_id}");
        }
        
        $hotel = $roomType->hotel;
        
        // 判断是自控库存还是第三方库存（通过 software_provider_id）
        if (empty($hotel->software_provider_id)) {
            // 自控库存：数据库事务扣减（使用乐观锁）
            return $this->processSelfHotelOrder($order, $item, $roomType);
        } else {
            // 第三方库存：通过软件服务商API对接（与门票一致）
            return $this->processThirdPartyHotelOrder($order, $item, $roomType, $hotel);
        }
    }
    
    /**
     * 处理酒店订单（支持重试）
     */
    private function processHotelOrderWithRetry(SystemPkgOrder $order, SystemPkgOrderItem $item): array
    {
        $maxRetries = $item->max_retries ?? 3;
        $retryCount = $item->retry_count ?? 0;
        
        while ($retryCount <= $maxRetries) {
            try {
                $result = $this->processHotelOrder($order, $item);
                
                if ($result['success']) {
                    return $result;
                }
            } catch (\Exception $e) {
                $retryCount++;
                $item->increment('retry_count');
                
                if ($retryCount > $maxRetries) {
                    // 超过最大重试次数，抛出异常
                    throw new \Exception("酒店订单处理失败，已重试{$maxRetries}次：{$e->getMessage()}");
                }
                
                // 等待后重试（指数退避）
                sleep(pow(2, $retryCount - 1));
            }
        }
        
        throw new \Exception("酒店订单处理失败，已重试{$maxRetries}次");
    }
    
    /**
     * 处理自控酒店订单（使用乐观锁）
     */
    private function processSelfHotelOrder(SystemPkgOrder $order, SystemPkgOrderItem $item, ResRoomType $roomType): array
    {
        $maxAttempts = 10; // 最大尝试次数（防止无限循环）
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                DB::beginTransaction();
                
                $currentDate = Carbon::parse($order->check_in_date);
                $stayDays = $order->stay_days;
                $requiredStock = $item->quantity;
                
                // 检查并扣减库存（使用乐观锁）
                $dailyStocks = [];
                
                for ($i = 0; $i < $stayDays; $i++) {
                    $date = $currentDate->copy()->addDays($i)->format('Y-m-d');
                    
                    // 读取当前版本号
                    $dailyStock = ResHotelDailyStock::where('room_type_id', $roomType->id)
                        ->where('biz_date', $date)
                        ->first();
                    
                    if (!$dailyStock) {
                        throw new \Exception("房型 {$roomType->name} 在日期 {$date} 没有库存数据");
                    }
                    
                    // 检查库存
                    if ($dailyStock->stock_available < $requiredStock) {
                        throw new \Exception("房型 {$roomType->name} 在日期 {$date} 库存不足（需要：{$requiredStock}，可用：{$dailyStock->stock_available}）");
                    }
                    
                    $dailyStocks[] = [
                        'id' => $dailyStock->id,
                        'version' => $dailyStock->version,
                    ];
                }
                
                // 使用乐观锁更新库存
                foreach ($dailyStocks as $stockData) {
                    $updated = ResHotelDailyStock::where('id', $stockData['id'])
                        ->where('version', $stockData['version']) // 版本号必须匹配
                        ->update([
                            'stock_sold' => DB::raw('stock_sold + ' . $requiredStock),
                            'version' => DB::raw('version + 1'), // 版本号递增
                        ]);
                    
                    if ($updated === 0) {
                        // 版本号不匹配，说明数据已被其他事务修改
                        throw new \Exception("库存已被其他订单占用，请重试");
                    }
                }
                
                DB::commit();
                
                $item->update([
                    'status' => SystemPkgOrderItemStatus::SUCCESS->value,
                    'processed_at' => now(),
                ]);
                
                return ['success' => true, 'item_id' => $item->id];
                
            } catch (\Exception $e) {
                DB::rollBack();
                
                // 如果是版本冲突，重试
                if (strpos($e->getMessage(), '版本') !== false || strpos($e->getMessage(), '已被其他') !== false) {
                    $attempt++;
                    if ($attempt < $maxAttempts) {
                        // 等待后重试（随机延迟，避免并发冲突）
                        usleep(rand(10000, 50000)); // 10-50ms
                        continue;
                    }
                }
                
                // 其他异常直接抛出
                throw $e;
            }
        }
        
        throw new \Exception("库存扣减失败，已达到最大尝试次数");
    }
    
    /**
     * 处理第三方酒店订单（使用软件服务商关联模式，与门票保持一致）
     */
    private function processThirdPartyHotelOrder(SystemPkgOrder $order, SystemPkgOrderItem $item, ResRoomType $roomType, ResHotel $hotel): array
    {
        // 获取软件服务商
        $softwareProvider = $hotel->softwareProvider;
        if (!$softwareProvider) {
            throw new \Exception("酒店未配置软件服务商：ID={$hotel->id}");
        }
        
        // 获取景区
        $scenicSpot = $hotel->scenicSpot;
        if (!$scenicSpot) {
            throw new \Exception("酒店未关联景区：ID={$hotel->id}");
        }
        
        // 获取资源配置（与门票一致）
        $config = ResourceConfig::with('softwareProvider')
            ->where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();
        
        if (!$config) {
            throw new \Exception("景区未配置该服务商的参数：scenic_spot_id={$scenicSpot->id}, software_provider_id={$softwareProvider->id}");
        }
        
        // 验证订单是否系统直连
        if (!$config->isOrderAuto()) {
            throw new \Exception("该酒店订单处理方式不是系统直连，无法自动处理");
        }
        
        // 根据软件服务商类型创建服务（与门票一致）
        $resourceService = match($softwareProvider->api_type) {
            'hengdian' => app(\App\Services\Resource\HengdianService::class)->setConfig($config),
            // 未来可以扩展其他资源方
            default => null,
        };
        
        if (!$resourceService) {
            throw new \Exception("不支持的软件服务商类型：{$softwareProvider->api_type}");
        }
        
        // 调用资源方接口预订酒店
        // 注意：这里需要根据实际API接口调整，可能需要扩展ResourceServiceInterface接口
        // 暂时使用confirmOrder接口，实际使用时需要调整
        $result = $resourceService->confirmOrder($this->buildHotelOrderData($order, $hotel, $roomType, $item));
        
        if ($result['success']) {
            $item->update([
                'status' => SystemPkgOrderItemStatus::SUCCESS->value,
                'resource_order_no' => $result['data']['order_no'] ?? $result['data']['confirm_no'] ?? '',
                'processed_at' => now(),
            ]);
            return ['success' => true, 'item_id' => $item->id];
        } else {
            throw new \Exception($result['message'] ?? '酒店下单失败');
        }
    }
    
    /**
     * 构建酒店订单数据
     */
    private function buildHotelOrderData(SystemPkgOrder $order, ResHotel $hotel, ResRoomType $roomType, SystemPkgOrderItem $item): array
    {
        // 这里需要根据实际API接口调整
        // 暂时返回一个模拟的订单对象，实际使用时需要创建临时Order对象或调整接口
        return [
            'hotel_id' => $hotel->external_hotel_id,
            'room_type_id' => $roomType->external_room_id,
            'check_in_date' => $order->check_in_date->format('Y-m-d'),
            'check_out_date' => $order->check_out_date->format('Y-m-d'),
            'quantity' => $item->quantity,
            'order_no' => $order->order_no,
        ];
    }
    
    /**
     * 获取资源名称
     */
    private function getResourceName(ProductBundleItem $item): string
    {
        if ($item->resource_type === 'TICKET') {
            $ticket = Ticket::find($item->resource_id);
            return $ticket ? $ticket->name : "门票ID:{$item->resource_id}";
        } elseif ($item->resource_type === 'HOTEL') {
            $roomType = ResRoomType::find($item->resource_id);
            return $roomType ? $roomType->name : "房型ID:{$item->resource_id}";
        }
        return "资源ID:{$item->resource_id}";
    }
    
    /**
     * 创建异常订单（人工处理）
     */
    private function createExceptionOrder(SystemPkgOrder $order, array $results): void
    {
        $failedItems = collect($results)->filter(fn($r) => !($r['success'] ?? false));
        
        SystemPkgExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => SystemPkgExceptionOrderType::SPLIT_ORDER_FAILED->value,
            'exception_message' => '拆单处理失败：' . $failedItems->count() . ' 个订单项处理失败',
            'exception_data' => [
                'failed_items' => $failedItems->toArray(),
                'results' => $results,
            ],
            'status' => SystemPkgExceptionOrderStatus::PENDING->value, // 待人工处理
        ]);
        
        // 更新订单状态为异常
        $order->update(['status' => SystemPkgOrderStatus::EXCEPTION->value]);
        
        Log::error('OrderSplitterService: 创建异常订单', [
            'order_id' => $order->id,
            'failed_count' => $failedItems->count(),
        ]);
    }
}



