<?php

namespace Tests\Unit;

use App\Enums\OtaPlatform as OtaPlatformEnum;
use App\Enums\OrderStatus;
use App\Models\Hotel;
use App\Models\Order;
use App\Models\OrderExternalPushLog;
use App\Models\OtaPlatform;
use App\Models\Product;
use App\Models\RoomType;
use App\Models\ScenicSpot;
use App\Models\ScenicSpotOrderPushConfig;
use App\Models\SoftwareProvider;
use App\Services\ExternalOrder\ExternalOrderPayloadBuilder;
use App\Services\ExternalOrder\ExternalOrderPushService;
use App\Support\ExternalOrder\ExternalOrderRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalOrderPushTest extends TestCase
{
    use RefreshDatabase;

    private function createSoftwareProvider(): SoftwareProvider
    {
        return SoftwareProvider::query()->create([
            'name' => '测试服务商',
            'code' => 'test_provider',
            'is_active' => true,
        ]);
    }

    public function test_build_create_payload_for_ctrip_order(): void
    {
        $provider = $this->createSoftwareProvider();
        $scenicSpot = ScenicSpot::query()->create([
            'name' => '测试景区',
            'code' => 'SS_TEST01',
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
            'contact_phone' => '0579-12345678',
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

        $order = Order::query()->create([
            'order_no' => 'ORD20260613001',
            'ota_order_no' => 'CT123456',
            'ota_platform_id' => $otaPlatform->id,
            'product_id' => $product->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => OrderStatus::PAID_PENDING,
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'room_count' => 1,
            'guest_count' => 2,
            'contact_name' => '张三',
            'contact_phone' => '13800138000',
            'guest_info' => [
                ['name' => '张三'],
                ['name' => '李四'],
            ],
            'total_amount' => 598,
            'settlement_amount' => 480,
        ]);

        $payload = app(ExternalOrderPayloadBuilder::class)->buildCreatePayload($order);

        $this->assertNotNull($payload);
        $this->assertSame(ExternalOrderRoute::ROUTE_ID_CTRIP, $payload['routeId']);
        $this->assertSame('CT123456', $payload['routeOrderId']);
        $this->assertSame('ORD20260613001', $payload['sourceOrderId']);
        $this->assertSame(ExternalOrderRoute::STATUS_PENDING, $payload['routeOrderStatus']);
        $this->assertSame(ExternalOrderRoute::ROUTE_CODE_CTRIP, $payload['routeCode']);
        $this->assertSame(598.0, $payload['totalPrice']);
        $this->assertSame(480.0, $payload['purchasePrice']);
        $this->assertSame('张三,李四', $payload['guestName']);
    }

    public function test_push_create_order_when_enabled(): void
    {
        config(['services.external_order_push.api_url' => 'https://api.tripfastpass.com']);

        Http::fake([
            'https://api.tripfastpass.com/api/hd/createOrder' => Http::response([
                'code' => 'OK',
                'traceId' => 'test-trace-id',
            ], 200),
        ]);

        $provider = $this->createSoftwareProvider();

        $scenicSpot = ScenicSpot::query()->create([
            'name' => '测试景区',
            'code' => 'SS_TEST02',
            'is_active' => true,
        ]);

        ScenicSpotOrderPushConfig::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'enabled' => true,
        ]);

        $otaPlatform = OtaPlatform::query()->create([
            'name' => '美团',
            'code' => OtaPlatformEnum::MEITUAN->value,
            'is_active' => true,
        ]);

        $hotel = Hotel::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'name' => '测试酒店',
            'contact_phone' => '0579-87654321',
            'is_active' => true,
        ]);

        $roomType = RoomType::query()->create([
            'hotel_id' => $hotel->id,
            'name' => '标间',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'name' => '测试产品',
            'software_provider_id' => $provider->id,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORD20260613002',
            'ota_order_no' => 'MT998877',
            'ota_platform_id' => $otaPlatform->id,
            'product_id' => $product->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => OrderStatus::PAID_PENDING,
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-02',
            'room_count' => 1,
            'guest_count' => 1,
            'contact_name' => '王五',
            'contact_phone' => '13900139000',
            'total_amount' => 300,
            'settlement_amount' => 250,
        ]);

        app(ExternalOrderPushService::class)->push(
            'order',
            $order->id,
            'create',
            ExternalOrderRoute::STATUS_PENDING,
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.tripfastpass.com/api/hd/createOrder'
                && $request['routeId'] === ExternalOrderRoute::ROUTE_ID_MEITUAN
                && $request['routeCode'] === ExternalOrderRoute::ROUTE_CODE_MEITUAN;
        });

        $this->assertDatabaseHas('order_external_push_logs', [
            'order_type' => 'order',
            'order_id' => $order->id,
            'push_type' => 'create',
            'status' => 'success',
        ]);
    }

    public function test_push_skipped_when_scenic_spot_disabled(): void
    {
        Http::fake();

        $provider = $this->createSoftwareProvider();

        $scenicSpot = ScenicSpot::query()->create([
            'name' => '未开启景区',
            'code' => 'SS_TEST03',
            'is_active' => true,
        ]);

        $otaPlatform = OtaPlatform::query()->create([
            'name' => '携程',
            'code' => OtaPlatformEnum::CTRIP->value,
            'is_active' => true,
        ]);

        $hotel = Hotel::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'name' => '酒店',
            'is_active' => true,
        ]);

        $roomType = RoomType::query()->create([
            'hotel_id' => $hotel->id,
            'name' => '房型',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'name' => '产品',
            'software_provider_id' => $provider->id,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORD20260613003',
            'ota_order_no' => 'CT000001',
            'ota_platform_id' => $otaPlatform->id,
            'product_id' => $product->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => OrderStatus::PAID_PENDING,
            'check_in_date' => '2026-08-01',
            'check_out_date' => '2026-08-02',
            'room_count' => 1,
            'guest_count' => 1,
            'contact_name' => '测试',
            'contact_phone' => '13800000000',
            'total_amount' => 100,
            'settlement_amount' => 80,
        ]);

        app(ExternalOrderPushService::class)->push(
            'order',
            $order->id,
            'create',
            ExternalOrderRoute::STATUS_PENDING,
        );

        Http::assertNothingSent();
        $this->assertDatabaseCount('order_external_push_logs', 0);
    }

    public function test_cancel_push_skipped_without_successful_create(): void
    {
        Http::fake();

        $order = $this->createOrderForExternalPush('ORD20260613004', 'MT112233');

        $result = app(ExternalOrderPushService::class)->push(
            'order',
            $order->id,
            'status_update',
            ExternalOrderRoute::STATUS_CANCELLED,
        );

        $this->assertNull($result);
        Http::assertNothingSent();
        $this->assertDatabaseCount('order_external_push_logs', 0);
    }

    public function test_cancel_push_proceeds_after_successful_create(): void
    {
        config(['services.external_order_push.api_url' => 'https://api.tripfastpass.com']);

        Http::fake([
            'https://api.tripfastpass.com/api/hd/updateOrderStatus' => Http::response([
                'code' => 'OK',
                'traceId' => 'cancel-trace-id',
            ], 200),
        ]);

        $order = $this->createOrderForExternalPush('ORD20260613005', 'MT445566');

        OrderExternalPushLog::query()->create([
            'order_type' => 'order',
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'scenic_spot_id' => $order->product->scenic_spot_id,
            'push_type' => 'create',
            'route_order_status' => ExternalOrderRoute::STATUS_PENDING,
            'endpoint' => '/api/hd/createOrder',
            'request_payload' => ['sourceOrderId' => $order->order_no],
            'status' => 'success',
            'attempt' => 1,
        ]);

        app(ExternalOrderPushService::class)->push(
            'order',
            $order->id,
            'status_update',
            ExternalOrderRoute::STATUS_CANCELLED,
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.tripfastpass.com/api/hd/updateOrderStatus'
                && $request['routeOrderStatus'] === ExternalOrderRoute::STATUS_CANCELLED;
        });

        $this->assertDatabaseHas('order_external_push_logs', [
            'order_type' => 'order',
            'order_id' => $order->id,
            'push_type' => 'status_update',
            'route_order_status' => ExternalOrderRoute::STATUS_CANCELLED,
            'status' => 'success',
        ]);
    }

    private function createOrderForExternalPush(string $orderNo, string $otaOrderNo): Order
    {
        $provider = $this->createSoftwareProvider();

        $scenicSpot = ScenicSpot::query()->create([
            'name' => '推送测试景区',
            'code' => 'SS_PUSH_'.substr($orderNo, -4),
            'is_active' => true,
        ]);

        ScenicSpotOrderPushConfig::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'enabled' => true,
        ]);

        $otaPlatform = OtaPlatform::query()->create([
            'name' => '美团',
            'code' => OtaPlatformEnum::MEITUAN->value,
            'is_active' => true,
        ]);

        $hotel = Hotel::query()->create([
            'scenic_spot_id' => $scenicSpot->id,
            'name' => '测试酒店',
            'contact_phone' => '0579-11112222',
            'is_active' => true,
        ]);

        $roomType = RoomType::query()->create([
            'hotel_id' => $hotel->id,
            'name' => '标间',
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
            'order_no' => $orderNo,
            'ota_order_no' => $otaOrderNo,
            'ota_platform_id' => $otaPlatform->id,
            'product_id' => $product->id,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'status' => OrderStatus::CANCEL_APPROVED,
            'check_in_date' => '2026-07-01',
            'check_out_date' => '2026-07-02',
            'room_count' => 1,
            'guest_count' => 1,
            'contact_name' => '测试',
            'contact_phone' => '13900139001',
            'total_amount' => 300,
            'settlement_amount' => 250,
        ]);
    }
}
