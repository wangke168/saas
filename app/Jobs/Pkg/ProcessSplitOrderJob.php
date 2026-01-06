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
                'item_type' => $item->item_type,
                'resource_id' => $item->resource_id,
            ]);

            // TODO: 根据订单项类型调用相应的接口
            // if ($item->item_type === PkgOrderItemType::TICKET->value) {
            //     // 调用门票下单接口
            // } elseif ($item->item_type === PkgOrderItemType::HOTEL->value) {
            //     // 调用酒店下单接口
            // }

            // 暂时标记为处理中（实际应该等接口调用成功后再更新状态）
            $item->update(['status' => PkgOrderItemStatus::PROCESSING]);

        } catch (\Exception $e) {
            Log::error('ProcessSplitOrderJob: 处理订单项失败', [
                'order_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            $item->update(['status' => PkgOrderItemStatus::FAILED]);
            throw $e;
        }
    }
}

