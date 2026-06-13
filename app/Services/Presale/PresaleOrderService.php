<?php

namespace App\Services\Presale;

use App\Enums\FulfillmentMode;
use App\Enums\OrderEntitlementStatus;
use App\Enums\OrderStatus;
use App\Models\Hotel;
use App\Models\Order;
use App\Models\OrderEntitlement;
use App\Models\Product;
use App\Models\ProductHotelRelation;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PresaleOrderService
{
    public static function isDeferredProduct(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        $mode = $product->fulfillment_mode;

        if ($mode instanceof FulfillmentMode) {
            return $mode === FulfillmentMode::Deferred;
        }

        // Product 模型将 fulfillment_mode 存为 string，需与枚举 value 比较
        return (string) $mode === FulfillmentMode::Deferred->value;
    }

    public static function shouldSkipResourceProcessing(Order $order): bool
    {
        if (PresaleFulfillmentOrderService::isFulfillmentChild($order)) {
            return false;
        }

        $order->loadMissing('product');

        return self::isDeferredProduct($order->product);
    }

    /**
     * 解析占位酒店/房型（满足 orders 表 NOT NULL，不参与 OTA 预售占房）
     *
     * @return array{hotel: Hotel, room_type: RoomType}
     */
    public function resolvePlaceholderHotelRoom(Product $presaleProduct): array
    {
        $relation = ProductHotelRelation::query()
            ->where('ticket_product_id', $presaleProduct->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['hotel', 'roomType'])
            ->first();

        if ($relation === null || $relation->hotel === null || $relation->roomType === null) {
            throw new \RuntimeException('预售产品未配置可选酒店房型（product_hotel_relations）');
        }

        return [
            'hotel' => $relation->hotel,
            'room_type' => $relation->roomType,
        ];
    }

    /**
     * 携程预下单：创建 deferred 父单 + 权益行（不占库存）
     */
    public function createOtaPreOrder(
        Product $product,
        int $otaPlatformId,
        string $ctripOrderId,
        string $useStartDate,
        ?string $useEndDate,
        int $quantity,
        float $salePrice,
        float $costPrice,
        array $passengers,
        array $contactInfo,
        ?string $cardNo,
        callable $generateOrderNo,
        array $metadata = [],
    ): Order {
        $placeholder = $this->resolvePlaceholderHotelRoom($product);
        $hotel = $placeholder['hotel'];
        $roomType = $placeholder['room_type'];

        $stayDays = $product->stay_days ?: 1;
        if (empty($useEndDate) || $useEndDate === $useStartDate) {
            $checkOutDate = Carbon::parse($useStartDate)->addDays($stayDays)->format('Y-m-d');
        } else {
            $checkOutDate = $useEndDate;
        }

        $unitBasePrice = round($salePrice, 2);

        $order = Order::create([
            'order_no' => $generateOrderNo(),
            'ota_order_no' => $ctripOrderId,
            'ota_platform_id' => $otaPlatformId,
            'product_id' => $product->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => OrderStatus::PAID_PENDING,
            'check_in_date' => $useStartDate,
            'check_out_date' => $checkOutDate,
            'room_count' => $quantity,
            'guest_count' => count($passengers) ?: 1,
            'contact_name' => $contactInfo['name'] ?? '',
            'contact_phone' => $contactInfo['mobile'] ?? $contactInfo['phone'] ?? '',
            'contact_email' => $contactInfo['email'] ?? '',
            'card_no' => $cardNo,
            'guest_info' => $passengers,
            'total_amount' => round($salePrice * $quantity, 2),
            'settlement_amount' => round($costPrice * $quantity, 2),
            'paid_at' => null,
            'remark' => $this->buildDeferredRemark($useStartDate, $useEndDate, $metadata),
        ]);

        $this->createEntitlements($order, $product, $quantity, $unitBasePrice > 0 ? $unitBasePrice : round($salePrice, 2));

        Log::info('预售预下单：已创建父单与权益（未占库存）', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $order;
    }

    public function createEntitlements(Order $order, Product $product, int $quantity, float $basePricePerUnit): void
    {
        for ($line = 1; $line <= max(1, $quantity); $line++) {
            OrderEntitlement::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'line_no' => $line,
                'entitlement_no' => $this->generateEntitlementNo($order->id, $line),
                'status' => OrderEntitlementStatus::Pending,
                'base_price' => $basePricePerUnit,
            ]);
        }
    }

    /**
     * 携程 PayPreOrder：标记已支付，不触发资源方/库存
     */
    public function markOtaPaid(Order $order, ?string $ctripItemId): void
    {
        $update = ['paid_at' => now()];
        if ($ctripItemId && ! $order->ctrip_item_id) {
            $update['ctrip_item_id'] = $ctripItemId;
        }
        $order->update($update);

        if ($order->status === OrderStatus::PAID_PENDING) {
            $order->update(['status' => OrderStatus::CONFIRMED]);
        }

        if ($order->entitlements()->count() === 0) {
            $order->load('product');
            $qty = max(1, (int) $order->room_count);
            $unit = $qty > 0 ? round((float) $order->total_amount / $qty, 2) : (float) $order->total_amount;
            $this->createEntitlements($order, $order->product, $qty, $unit);
        }
    }

    protected function generateEntitlementNo(int $orderId, int $lineNo): string
    {
        return 'E'.date('Ymd').str_pad((string) $orderId, 8, '0', STR_PAD_LEFT).str_pad((string) $lineNo, 2, '0', STR_PAD_LEFT);
    }

    protected function buildDeferredRemark(string $useStartDate, ?string $useEndDate, array $metadata = []): string
    {
        $payload = array_merge([
            'type' => 'presale_deferred',
            'ota_window_start' => $useStartDate,
            'ota_window_end' => $useEndDate ?: $useStartDate,
        ], $metadata);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'presale_deferred';
    }
}
