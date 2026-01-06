<?php

namespace App\Services\Pkg;

use App\Enums\PkgOrderStatus;
use App\Models\Pkg\PkgOrder;
use Illuminate\Support\Facades\DB;

class PkgOrderService
{
    /**
     * 生成订单号
     */
    protected function generateOrderNo(): string
    {
        return 'PKG' . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * 创建订单
     */
    public function createOrder(array $data): PkgOrder
    {
        return DB::transaction(function () use ($data) {
            $order = PkgOrder::create(array_merge($data, [
                'order_no' => $this->generateOrderNo(),
                'status' => PkgOrderStatus::PAID,
            ]));

            return $order->load(['product', 'hotel', 'roomType']);
        });
    }

    /**
     * 更新订单状态
     */
    public function updateOrderStatus(PkgOrder $order, PkgOrderStatus $newStatus): void
    {
        DB::transaction(function () use ($order, $newStatus) {
            $order->update(['status' => $newStatus]);

            // 根据状态更新相关时间戳
            if ($newStatus === PkgOrderStatus::CONFIRMED) {
                $order->update(['confirmed_at' => now()]);
            } elseif ($newStatus === PkgOrderStatus::CANCELLED) {
                $order->update(['cancelled_at' => now()]);
            }
        });
    }
}
