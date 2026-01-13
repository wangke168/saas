<?php

namespace App\Jobs\Pkg;

use App\Models\Pkg\PkgOrder;
use App\Models\Pkg\PkgOrderItem;
use App\Enums\PkgOrderItemStatus;
use App\Enums\PkgOrderItemType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 处理拆分后的订单项
 * 在订单拆分完成后，异步处理订单项（调用第三方API等）
 */
class ProcessSplitOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public int $pkgOrderId
    ) {}

    public function handle(): void
    {
        try {
            $order = PkgOrder::with(['items', 'product.bundleItems.ticket'])->find($this->pkgOrderId);
            if (!$order) {
                Log::warning('ProcessSplitOrderJob: 订单不存在', [
                    'pkg_order_id' => $this->pkgOrderId,
                ]);
                return;
            }

            Log::info('ProcessSplitOrderJob: 开始处理拆分后的订单项', [
                'pkg_order_id' => $this->pkgOrderId,
                'order_no' => $order->order_no,
                'items_count' => $order->items->count(),
            ]);

            // 处理每个订单项
            foreach ($order->items as $item) {
                $this->processOrderItem($order, $item);
            }

            Log::info('ProcessSplitOrderJob: 订单项处理完成', [
                'pkg_order_id' => $this->pkgOrderId,
            ]);

            // TODO: 后续可以在这里：
            // 1. 调用门票下单接口
            // 2. 调用酒店下单接口
            // 3. 更新订单项状态
            // 4. 处理异常情况

        } catch (\Exception $e) {
            Log::error('ProcessSplitOrderJob: 处理失败', [
                'pkg_order_id' => $this->pkgOrderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * 处理单个订单项
     */
    protected function processOrderItem(PkgOrder $order, PkgOrderItem $item): void
    {
        try {
            Log::info('ProcessSplitOrderJob: 处理订单项', [
                'order_item_id' => $item->id,
                'item_type' => $item->item_type->value,
                'resource_id' => $item->resource_id,
            ]);

            // 更新状态为处理中
            $item->update([
                'status' => PkgOrderItemStatus::PROCESSING,
                'processed_at' => now(),
            ]);

            // 根据订单项类型处理
            if ($item->item_type === PkgOrderItemType::TICKET) {
                $this->processTicketItem($order, $item);
            } elseif ($item->item_type === PkgOrderItemType::HOTEL) {
                $this->processHotelItem($order, $item);
            } else {
                Log::warning('ProcessSplitOrderJob: 未知的订单项类型', [
                    'order_item_id' => $item->id,
                    'item_type' => $item->item_type->value,
                ]);
                $item->update([
                    'status' => PkgOrderItemStatus::FAILED,
                    'error_message' => '未知的订单项类型',
                ]);
            }

            // 检查所有订单项是否处理完成，更新主订单状态
            $this->updateMainOrderStatus($order);

        } catch (\Exception $e) {
            Log::error('ProcessSplitOrderJob: 处理订单项失败', [
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $item->update([
                'status' => PkgOrderItemStatus::FAILED,
                'error_message' => $e->getMessage(),
            ]);
            
            // 更新主订单状态
            $this->updateMainOrderStatus($order);
            
            // 不抛出异常，继续处理其他订单项
        }
    }

    /**
     * 处理门票订单项
     */
    protected function processTicketItem(PkgOrder $order, PkgOrderItem $item): void
    {
        try {
            // 加载门票信息
            $ticket = \App\Models\Ticket::with(['scenicSpot.resourceConfig', 'scenicSpot.softwareProvider'])
                ->find($item->resource_id);
            
            if (!$ticket) {
                throw new \Exception('门票不存在：resource_id=' . $item->resource_id);
            }

            $scenicSpot = $ticket->scenicSpot;
            if (!$scenicSpot) {
                throw new \Exception('门票未关联景区');
            }

            // 检查是否系统直连
            $isSystemConnected = $this->isSystemConnectedForTicket($ticket);
            
            if (!$isSystemConnected) {
                // 非系统直连：标记为待人工处理
                Log::info('ProcessSplitOrderJob: 门票订单项非系统直连，标记为待人工处理', [
                    'order_item_id' => $item->id,
                    'ticket_id' => $ticket->id,
                    'ticket_name' => $ticket->name,
                ]);
                
                $item->update([
                    'status' => PkgOrderItemStatus::PENDING,
                    'error_message' => '非系统直连订单，等待人工处理',
                ]);
                return;
            }

            // 系统直连：调用门票资源方接口
            // TODO: 实现门票下单接口调用
            // 目前先标记为成功，后续需要实现实际接口调用
            Log::info('ProcessSplitOrderJob: 门票订单项系统直连，待实现接口调用', [
                'order_item_id' => $item->id,
                'ticket_id' => $ticket->id,
                'ticket_name' => $ticket->name,
            ]);
            
            // 暂时标记为成功（实际应该等接口调用成功后再更新）
            $item->update([
                'status' => PkgOrderItemStatus::SUCCESS,
                'resource_order_no' => 'TEMP_' . $item->id, // 临时订单号，实际应从接口返回
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessSplitOrderJob: 处理门票订单项失败', [
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 处理酒店订单项
     */
    protected function processHotelItem(PkgOrder $order, PkgOrderItem $item): void
    {
        try {
            // 加载房型信息
            $roomType = \App\Models\Res\ResRoomType::with(['hotel.scenicSpot.resourceConfig', 'hotel.scenicSpot.softwareProvider'])
                ->find($item->resource_id);
            
            if (!$roomType) {
                throw new \Exception('房型不存在：resource_id=' . $item->resource_id);
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                throw new \Exception('房型未关联酒店');
            }

            $scenicSpot = $hotel->scenicSpot;
            if (!$scenicSpot) {
                throw new \Exception('酒店未关联景区');
            }

            // 检查是否系统直连
            $isSystemConnected = $this->isSystemConnectedForHotel($hotel);
            
            if (!$isSystemConnected) {
                // 非系统直连：标记为待人工处理
                Log::info('ProcessSplitOrderJob: 酒店订单项非系统直连，标记为待人工处理', [
                    'order_item_id' => $item->id,
                    'room_type_id' => $roomType->id,
                    'room_type_name' => $roomType->name,
                ]);
                
                $item->update([
                    'status' => PkgOrderItemStatus::PENDING,
                    'error_message' => '非系统直连订单，等待人工处理',
                ]);
                return;
            }

            // 系统直连：调用酒店资源方接口
            // TODO: 实现酒店下单接口调用
            // 目前先标记为成功，后续需要实现实际接口调用
            Log::info('ProcessSplitOrderJob: 酒店订单项系统直连，待实现接口调用', [
                'order_item_id' => $item->id,
                'room_type_id' => $roomType->id,
                'room_type_name' => $roomType->name,
            ]);
            
            // 暂时标记为成功（实际应该等接口调用成功后再更新）
            $item->update([
                'status' => PkgOrderItemStatus::SUCCESS,
                'resource_order_no' => 'TEMP_' . $item->id, // 临时订单号，实际应从接口返回
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessSplitOrderJob: 处理酒店订单项失败', [
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 检查门票是否系统直连
     */
    protected function isSystemConnectedForTicket(\App\Models\Ticket $ticket): bool
    {
        $scenicSpot = $ticket->scenicSpot;
        if (!$scenicSpot) {
            return false;
        }

        // 获取门票的软件服务商（如果门票有software_provider_id字段）
        // 如果没有，则从景区获取
        $softwareProvider = $ticket->softwareProvider ?? $scenicSpot->softwareProvider;
        if (!$softwareProvider) {
            return false;
        }

        // 获取资源配置
        $config = \App\Models\ResourceConfig::where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();
            
        if (!$config) {
            return false;
        }

        // 检查同步模式
        $syncMode = $config->extra_config['sync_mode'] ?? [];
        $orderMode = $syncMode['order'] ?? 'manual';
        
        return $orderMode === 'auto';
    }

    /**
     * 检查酒店是否系统直连
     */
    protected function isSystemConnectedForHotel(\App\Models\Res\ResHotel $hotel): bool
    {
        $scenicSpot = $hotel->scenicSpot;
        if (!$scenicSpot) {
            return false;
        }

        // 获取酒店的软件服务商（如果酒店有software_provider_id字段）
        // 如果没有，则从景区获取
        $softwareProvider = $hotel->softwareProvider ?? $scenicSpot->softwareProvider;
        if (!$softwareProvider) {
            return false;
        }

        // 获取资源配置
        $config = \App\Models\ResourceConfig::where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();
            
        if (!$config) {
            return false;
        }

        // 检查同步模式
        $syncMode = $config->extra_config['sync_mode'] ?? [];
        $orderMode = $syncMode['order'] ?? 'manual';
        
        return $orderMode === 'auto';
    }

    /**
     * 更新主订单状态
     */
    protected function updateMainOrderStatus(PkgOrder $order): void
    {
        // 重新加载订单项
        $order->refresh();
        $order->load('items');
        
        $items = $order->items;
        if ($items->isEmpty()) {
            return;
        }

        // 检查所有订单项的状态
        $allSuccess = $items->every(function ($item) {
            return $item->status === PkgOrderItemStatus::SUCCESS;
        });
        
        $hasFailed = $items->contains(function ($item) {
            return $item->status === PkgOrderItemStatus::FAILED;
        });
        
        $hasPending = $items->contains(function ($item) {
            return in_array($item->status, [
                PkgOrderItemStatus::PENDING,
                PkgOrderItemStatus::PROCESSING,
            ]);
        });

        // 更新主订单状态
        if ($allSuccess) {
            // 所有订单项都成功，主订单状态为已确认
            if ($order->status !== \App\Enums\PkgOrderStatus::CONFIRMED) {
                $order->update([
                    'status' => \App\Enums\PkgOrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                ]);
                
                Log::info('ProcessSplitOrderJob: 主订单状态更新为已确认', [
                    'pkg_order_id' => $order->id,
                    'order_no' => $order->order_no,
                ]);
                
                // TODO: 通知OTA平台订单确认
                // \App\Jobs\NotifyOtaOrderStatusJob::dispatch($order);
            }
        } elseif ($hasFailed && !$hasPending) {
            // 有失败且没有待处理的，主订单状态为失败
            if ($order->status !== \App\Enums\PkgOrderStatus::FAILED) {
                $order->update([
                    'status' => \App\Enums\PkgOrderStatus::FAILED,
                ]);
                
                Log::warning('ProcessSplitOrderJob: 主订单状态更新为失败', [
                    'pkg_order_id' => $order->id,
                    'order_no' => $order->order_no,
                ]);
            }
        }
        // 如果有待处理的订单项，主订单状态保持CONFIRMED（处理中）
    }
}

