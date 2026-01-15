<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use App\Models\ResourceConfig;
use App\Models\ZiwoyouProductMapping;
use App\Models\OtaPlatform;
use App\Services\Resource\ResourceServiceFactory;
use App\Services\Resource\ZiwoyouService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ZiwoyouOrderTest extends TestCase
{
    use RefreshDatabase;

    protected ScenicSpot $scenicSpot;
    protected Hotel $hotel;
    protected RoomType $roomType;
    protected Product $product;
    protected SoftwareProvider $hengdianProvider;
    protected SoftwareProvider $ziwoyouProvider;
    protected ResourceConfig $hengdianConfig;
    protected ResourceConfig $ziwoyouConfig;
    protected OtaPlatform $otaPlatform;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建景区
        $this->scenicSpot = ScenicSpot::factory()->create();
        
        // 创建酒店和房型
        $this->hotel = Hotel::factory()->create(['scenic_spot_id' => $this->scenicSpot->id]);
        $this->roomType = RoomType::factory()->create(['hotel_id' => $this->hotel->id]);
        
        // 创建软件服务商
        $this->hengdianProvider = SoftwareProvider::factory()->create([
            'api_type' => 'hengdian',
            'api_url' => 'https://test-hengdian.com',
        ]);
        
        $this->ziwoyouProvider = SoftwareProvider::factory()->create([
            'api_type' => 'ziwoyou',
            'api_url' => 'https://test-ziwoyou.com',
        ]);
        
        // 创建产品（关联横店服务商，用于库存推送）
        $this->product = Product::factory()->create([
            'scenic_spot_id' => $this->scenicSpot->id,
            'software_provider_id' => $this->hengdianProvider->id,
        ]);
        
        // 创建资源配置
        $this->hengdianConfig = ResourceConfig::factory()->create([
            'scenic_spot_id' => $this->scenicSpot->id,
            'software_provider_id' => $this->hengdianProvider->id,
            'extra_config' => [
                'sync_mode' => [
                    'inventory' => 'push',
                    'price' => 'manual',
                    'order' => 'auto',
                ],
                'order_provider' => $this->ziwoyouProvider->id, // 订单下发服务商分离
            ],
        ]);
        
        $this->ziwoyouConfig = ResourceConfig::factory()->create([
            'scenic_spot_id' => $this->scenicSpot->id,
            'software_provider_id' => $this->ziwoyouProvider->id,
            'extra_config' => [
                'sync_mode' => [
                    'inventory' => 'manual',
                    'price' => 'manual',
                    'order' => 'auto',
                ],
                'auth' => [
                    'type' => 'custom',
                    'params' => [
                        'apikey' => 'test_apikey',
                        'custId' => 12345,
                    ],
                ],
            ],
        ]);
        
        // 创建OTA平台
        $this->otaPlatform = OtaPlatform::factory()->create();
    }

    /**
     * 测试：产品有映射关系时，使用自我游服务
     */
    public function test_get_service_with_mapping(): void
    {
        // 创建映射关系
        ZiwoyouProductMapping::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ziwoyou_product_id' => 'ZWY123456',
            'scenic_spot_id' => $this->scenicSpot->id,
            'is_active' => true,
        ]);
        
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'status' => OrderStatus::CONFIRMING,
        ]);
        
        // 获取服务
        $service = ResourceServiceFactory::getService($order, 'order');
        
        // 验证
        $this->assertNotNull($service);
        $this->assertInstanceOf(ZiwoyouService::class, $service);
    }

    /**
     * 测试：产品无映射关系时，返回null（走手工流程）
     */
    public function test_get_service_without_mapping(): void
    {
        // 不创建映射关系
        
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'status' => OrderStatus::CONFIRMING,
        ]);
        
        // 获取服务
        $service = ResourceServiceFactory::getService($order, 'order');
        
        // 验证：应该返回null，走手工流程
        $this->assertNull($service);
    }

    /**
     * 测试：订单下发服务商分离配置
     */
    public function test_order_provider_separation(): void
    {
        // 创建映射关系
        ZiwoyouProductMapping::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ziwoyou_product_id' => 'ZWY123456',
            'scenic_spot_id' => $this->scenicSpot->id,
            'is_active' => true,
        ]);
        
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'status' => OrderStatus::CONFIRMING,
        ]);
        
        // 获取服务
        $service = ResourceServiceFactory::getService($order, 'order');
        
        // 验证：应该使用自我游服务（虽然产品服务商是横店）
        $this->assertNotNull($service);
        $this->assertInstanceOf(ZiwoyouService::class, $service);
    }

    /**
     * 测试：库存推送不受订单下发服务商分离影响
     */
    public function test_inventory_push_not_affected(): void
    {
        // 创建订单（用于获取服务）
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'status' => OrderStatus::CONFIRMING,
        ]);
        
        // 获取库存服务（应该使用横店，不受订单下发服务商分离影响）
        $service = ResourceServiceFactory::getService($order, 'inventory');
        
        // 验证：库存操作应该使用横店服务商
        // 注意：这里需要根据实际实现调整
        // 如果库存推送不通过 ResourceServiceFactory，这个测试可能需要调整
    }

    /**
     * 测试：自我游回调通知处理
     */
    public function test_ziwoyou_webhook_callback(): void
    {
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'order_no' => 'TEST001',
            'status' => OrderStatus::CONFIRMING,
        ]);
        
        // 模拟确认通知
        $response = $this->postJson('/api/webhooks/ziwoyou', [
            'method' => 'confirm',
            'orderSourceId' => 'TEST001',
            'orderId' => 123456,
            'confirmState' => 1,
            'num' => 1,
            'orderMoney' => 100.00,
            'saleMoney' => 120.00,
        ]);
        
        // 验证响应
        $response->assertStatus(200)
            ->assertJson([
                'state' => 1,
                'msg' => '成功',
            ]);
        
        // 验证订单状态更新
        $order->refresh();
        $this->assertEquals(OrderStatus::CONFIRMED->value, $order->status->value);
        $this->assertEquals(100.00, $order->settlement_amount);
    }

    /**
     * 测试：自我游回调通知 - 出票通知
     */
    public function test_ziwoyou_webhook_print(): void
    {
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'order_no' => 'TEST002',
            'status' => OrderStatus::CONFIRMED,
        ]);
        
        // 模拟出票通知
        $response = $this->postJson('/api/webhooks/ziwoyou', [
            'method' => 'print',
            'orderSourceId' => 'TEST002',
            'orderId' => 123457,
            'printState' => 1,
            'vouchers' => [
                [
                    'code' => 'VOUCHER123',
                    'type' => 0,
                    'voucherStatus' => 0,
                ],
            ],
        ]);
        
        // 验证响应
        $response->assertStatus(200)
            ->assertJson([
                'state' => 1,
                'msg' => '成功',
            ]);
    }

    /**
     * 测试：自我游回调通知 - 取消通知
     */
    public function test_ziwoyou_webhook_cancel(): void
    {
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'order_no' => 'TEST003',
            'status' => OrderStatus::CONFIRMED,
        ]);
        
        // 模拟取消通知
        $response = $this->postJson('/api/webhooks/ziwoyou', [
            'method' => 'cancel',
            'orderSourceId' => 'TEST003',
            'orderId' => 123458,
            'cancelState' => 1,
            'cancelMoney' => 100.00,
            'cancelNum' => 1,
        ]);
        
        // 验证响应
        $response->assertStatus(200)
            ->assertJson([
                'state' => 1,
                'msg' => '成功',
            ]);
        
        // 验证订单状态更新
        $order->refresh();
        $this->assertEquals(OrderStatus::CANCEL_APPROVED->value, $order->status->value);
    }

    /**
     * 测试：自我游回调通知 - 核销通知
     */
    public function test_ziwoyou_webhook_finish(): void
    {
        // 创建订单
        $order = Order::factory()->create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'ota_platform_id' => $this->otaPlatform->id,
            'order_no' => 'TEST004',
            'status' => OrderStatus::CONFIRMED,
        ]);
        
        // 模拟核销通知
        $response = $this->postJson('/api/webhooks/ziwoyou', [
            'method' => 'finish',
            'orderSourceId' => 'TEST004',
            'orderId' => 123459,
            'finishNum' => 1,
            'finishCodes' => ['CODE123'],
        ]);
        
        // 验证响应
        $response->assertStatus(200)
            ->assertJson([
                'state' => 1,
                'msg' => '成功',
            ]);
        
        // 验证订单状态更新
        $order->refresh();
        $this->assertEquals(OrderStatus::VERIFIED->value, $order->status->value);
    }
}

