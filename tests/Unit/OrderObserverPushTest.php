<?php

namespace Tests\Unit;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Enums\OrderStatus;
use App\Jobs\PushExternalOrderJob;
use App\Models\Hotel;
use App\Models\Order;
use App\Models\OtaPlatform;
use App\Models\Product;
use App\Models\RoomType;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use App\Support\ExternalOrder\ExternalOrderRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderObserverPushTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(OrderStatus $status = OrderStatus::CONFIRMING): Order
    {
        $provider = SoftwareProvider::query()->create([
            'name' => '测试服务商',
            'code' => 'test_provider',
            'is_active' => true,
        ]);

        $scenicSpot = ScenicSpot::query()->create([
            'name' => '测试景区',
            'code' => 'SS_OBSERVER',
            'is_active' => true,
        ]);

        $otaPlatform = OtaPlatform::query()->create([
            'name' => '携程',
            'code' => OtaPlatformEnum::CTRIP->value,
            'is_active' => true,
        ]);

        $hotel = Hotel::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'name' => '测试酒店',
            'is_active' => true,
        ]);

        $roomType = RoomType::query()->create([
            'hotel_id' => $hotel->id,
            'name' => '大床房',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'name' => '测试产品',
            'software_provider_id' => $provider->id,
        ]);

        return Order::query()->create([
            'order_no' => 'ORD'.uniqid(),
            'ota_order_no' => 'CT'.uniqid(),
            'ota_platform_id' => $otaPlatform->id,
            'product_id' => $product->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => $status,
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'room_count' => 1,
            'guest_count' => 2,
            'contact_name' => '张三',
            'contact_phone' => '13800138000',
            'total_amount' => 598,
            'settlement_amount' => 480,
        ]);
    }

    public function test_direct_status_update_dispatches_confirmed_push(): void
    {
        Queue::fake();

        $order = $this->createOrder(OrderStatus::CONFIRMING);
        $order->update(['status' => OrderStatus::CONFIRMED]);

        Queue::assertPushed(PushExternalOrderJob::class, function (PushExternalOrderJob $job) use ($order) {
            return $job->orderType === 'order'
                && $job->orderId === $order->id
                && $job->pushType === 'status_update'
                && $job->routeOrderStatus === ExternalOrderRoute::STATUS_CONFIRMED;
        });
    }

    public function test_direct_status_update_dispatches_verified_push(): void
    {
        Queue::fake();

        $order = $this->createOrder(OrderStatus::CONFIRMED);
        $order->update(['status' => OrderStatus::VERIFIED]);

        Queue::assertPushed(PushExternalOrderJob::class, function (PushExternalOrderJob $job) use ($order) {
            return $job->orderType === 'order'
                && $job->orderId === $order->id
                && $job->pushType === 'status_update'
                && $job->routeOrderStatus === ExternalOrderRoute::STATUS_VERIFIED;
        });
    }

    public function test_confirming_status_does_not_dispatch_push(): void
    {
        Queue::fake();

        $order = $this->createOrder(OrderStatus::PAID_PENDING);
        $order->update(['status' => OrderStatus::CONFIRMING]);

        Queue::assertNothingPushed();
    }
}
