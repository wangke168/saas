<?php

namespace App\Support;

use App\Enums\ExceptionOrderStatus;
use App\Enums\ExceptionOrderType;
use App\Models\ExceptionOrder;
use App\Models\Order;
use App\Services\OTA\MeituanLevelMapService;
use Illuminate\Support\Collection;

class MeituanSkuConfirmation
{
    public static function pendingException(Order $order): ?ExceptionOrder
    {
        return ExceptionOrder::query()
            ->where('order_id', $order->id)
            ->where('status', ExceptionOrderStatus::PENDING)
            ->where(function ($query) {
                $query->where('exception_type', ExceptionOrderType::SKU_PENDING)
                    ->orWhere('exception_data->missing_partner_primary_key', true);
            })
            ->first();
    }

    /**
     * @param  Collection<int, int|string>  $orderIds
     * @return Collection<int, ExceptionOrder>
     */
    public static function pendingExceptionsByOrderIds(Collection $orderIds): Collection
    {
        if ($orderIds->isEmpty()) {
            return collect();
        }

        return ExceptionOrder::query()
            ->whereIn('order_id', $orderIds->all())
            ->where('status', ExceptionOrderStatus::PENDING)
            ->where(function ($query) {
                $query->where('exception_type', ExceptionOrderType::SKU_PENDING)
                    ->orWhere('exception_data->missing_partner_primary_key', true);
            })
            ->get()
            ->keyBy('order_id');
    }

    /**
     * @return array{required: bool, candidates: array<int, mixed>, level_ids: mixed, unit_price: mixed, message: string}|null
     */
    public static function payload(?ExceptionOrder $exception, ?Order $order = null): ?array
    {
        if ($exception === null) {
            return null;
        }

        $data = $exception->exception_data ?? [];
        $order ??= $exception->order;
        $candidates = $data['candidates'] ?? [];
        if ((! is_array($candidates) || $candidates === []) && $order?->product !== null && $order->check_in_date !== null) {
            $candidates = app(MeituanLevelMapService::class)->candidatesForProductDate(
                $order->product,
                $order->check_in_date->format('Y-m-d'),
            );
        }

        return [
            'required' => ($data['sku_confirmed'] ?? false) !== true,
            'candidates' => is_array($candidates) ? $candidates : [],
            'level_ids' => $data['level_ids'] ?? $order?->meituan_level_ids,
            'unit_price' => $data['unit_price'] ?? null,
            'message' => (string) $exception->exception_message,
        ];
    }
}
