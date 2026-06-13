<?php

namespace App\Services\Presale;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderBooking;
use App\Models\OrderLog;
use App\Support\MpBookingGuestInfo;
use Illuminate\Support\Facades\Log;

/**
 * 预售小程序预约成功后创建酒店履约子单（方案 B），父单保留 OTA 购票信息。
 */
final class PresaleFulfillmentOrderService
{
    public function createFromBooking(OrderBooking $booking, Order $parentOrder): Order
    {
        $booking->loadMissing(['presaleProduct', 'hotel', 'roomType']);

        $checkIn = $booking->check_in_date?->format('Y-m-d');
        if ($checkIn === null || $checkIn === '') {
            throw new \RuntimeException('预约单缺少入住日期');
        }

        $checkOut = $booking->check_out_date?->format('Y-m-d') ?? $checkIn;
        $guestInfo = MpBookingGuestInfo::toOrderGuestInfo($booking);
        $guestName = trim((string) ($booking->guest_name ?? ''));
        $guestPhone = trim((string) ($booking->guest_phone ?? ''));

        $otaSuffix = $booking->booking_no ?: ('B'.$booking->id);
        $otaOrderNo = ($parentOrder->ota_order_no ?? $parentOrder->order_no).'-'.$otaSuffix;

        $basePrice = max(0, (float) $booking->base_price);
        $surcharge = max(0, (float) $booking->surcharge_amount);
        $packageSalePrice = max(0, (float) $booking->package_sale_price);
        // 横店等产品价格校验：按预约当日套餐售价（= 权益基础价 + 补差）
        $fulfillmentAmount = $packageSalePrice > 0 ? $packageSalePrice : ($basePrice + $surcharge);

        $fulfillmentOrder = Order::create([
            'order_no' => $this->generateOrderNo(),
            'parent_order_id' => $parentOrder->id,
            'order_type' => 'hotel',
            'ota_order_no' => $otaOrderNo,
            'ota_platform_id' => $parentOrder->ota_platform_id,
            'product_id' => $booking->presale_product_id ?? $booking->package_product_id ?? $parentOrder->product_id,
            'hotel_id' => $booking->hotel_id,
            'room_type_id' => $booking->room_type_id,
            'status' => OrderStatus::CONFIRMING,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'room_count' => 1,
            'guest_count' => 1,
            'contact_name' => $guestName !== '' ? $guestName : $parentOrder->contact_name,
            'contact_phone' => $guestPhone !== '' ? $guestPhone : $parentOrder->contact_phone,
            'contact_email' => $parentOrder->contact_email,
            'guest_info' => $guestInfo,
            'real_name_type' => $parentOrder->real_name_type,
            'credential_list' => $parentOrder->credential_list,
            'total_amount' => round($fulfillmentAmount, 2),
            'settlement_amount' => round($fulfillmentAmount, 2),
            'paid_at' => $booking->paid_at ?? now(),
            'remark' => json_encode([
                'type' => 'presale_fulfillment',
                'parent_order_id' => $parentOrder->id,
                'booking_id' => $booking->id,
                'booking_no' => $booking->booking_no,
                'base_price' => $basePrice,
                'surcharge_amount' => $surcharge,
                'package_sale_price' => $fulfillmentAmount,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'presale_fulfillment',
        ]);

        OrderLog::create([
            'order_id' => $fulfillmentOrder->id,
            'from_status' => null,
            'to_status' => OrderStatus::CONFIRMING->value,
            'remark' => '预售预约履约子单（来源 booking '.$booking->id.'）',
            'operator_id' => null,
        ]);

        Log::info('预售履约子单已创建', [
            'fulfillment_order_id' => $fulfillmentOrder->id,
            'fulfillment_order_no' => $fulfillmentOrder->order_no,
            'parent_order_id' => $parentOrder->id,
            'booking_id' => $booking->id,
            'ota_order_no' => $otaOrderNo,
        ]);

        return $fulfillmentOrder;
    }

    public static function isFulfillmentChild(Order $order): bool
    {
        return $order->parent_order_id !== null
            && $order->order_type === 'hotel';
    }

    public static function isPresaleParentOrder(Order $order): bool
    {
        if (self::isFulfillmentChild($order)) {
            return false;
        }

        return PresaleOrderService::isDeferredProduct($order->product);
    }

    /**
     * @return array{is_presale_parent: bool, is_presale_fulfillment_child: bool}
     */
    public static function presaleDisplayFlags(Order $order): array
    {
        $isChild = self::isFulfillmentChild($order);

        return [
            'is_presale_fulfillment_child' => $isChild,
            'is_presale_parent' => ! $isChild && self::isPresaleParentOrder($order),
        ];
    }

    protected function generateOrderNo(): string
    {
        return 'ORD'.date('YmdHis').str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}
