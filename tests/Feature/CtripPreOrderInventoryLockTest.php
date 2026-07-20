<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExceptionOrderType;
use App\Http\Controllers\Webhooks\CtripController;
use App\Models\ExceptionOrder;
use App\Models\Hotel;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Product;
use App\Models\ProductRoomInventoryControl;
use App\Models\RoomType;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 携程预下单库存锁定测试
 *
 * 覆盖：
 * 1. InventoryService 行锁版“检查+扣减”逻辑
 * 2. 预下单库存锁定失败转异常订单（不拒单）
 * 3. 未锁定库存的订单取消时跳过库存释放
 *
 * 注：项目迁移链路含时间戳倒挂/MySQL 专有 SQL，无法在 sqlite 下跑通全量
 * RefreshDatabase，因此本测试自行搭建最小必要 schema。
 */
class CtripPreOrderInventoryLockTest extends TestCase
{
    private const CHECK_IN_DATE = '2026-08-01';
    private const PLU = 'H00013|R00038|000012';

    private Hotel $hotel;
    private RoomType $roomType;
    private Product $product;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        // 强制走内存 sqlite，避免污染开发库
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'sync',
        ]);
        DB::purge();
        DB::reconnect('sqlite');

        Queue::fake();

        $this->createMinimalSchema();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('exception_orders');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_room_inventory_controls');
        Schema::dropIfExists('prices');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('product_unavailable_periods');
        Schema::dropIfExists('products');
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('hotels');
        Schema::dropIfExists('ota_platforms');
        Schema::dropIfExists('software_providers');
        Schema::dropIfExists('scenic_spots');

        parent::tearDown();
    }

    private function createMinimalSchema(): void
    {
        Schema::create('scenic_spots', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('software_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('api_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ota_platforms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hotels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('room_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id');
            $table->unsignedBigInteger('software_provider_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('stay_days')->default(1);
            $table->string('fulfillment_mode')->default('immediate');
            $table->boolean('is_active')->default(true);
            $table->boolean('id_region_restriction_enabled')->default(false);
            $table->json('id_region_prefixes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_unavailable_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_type_id');
            $table->date('date');
            $table->integer('total_quantity')->default(0);
            $table->integer('available_quantity')->default(0);
            $table->integer('locked_quantity')->default(0);
            $table->string('source')->default('manual');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['room_type_id', 'date']);
        });

        Schema::create('prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('room_type_id');
            $table->date('date');
            $table->decimal('market_price', 10, 2)->default(0);
            $table->decimal('settlement_price', 10, 2)->default(0);
            $table->decimal('sale_price', 10, 2)->default(0);
            $table->string('source')->default('manual');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_room_inventory_controls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('room_type_id');
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no')->unique();
            $table->string('ota_order_no')->nullable();
            $table->unsignedBigInteger('ota_platform_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('room_type_id');
            $table->string('status');
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->integer('room_count')->default(1);
            $table->integer('guest_count')->default(1);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('card_no')->nullable();
            $table->text('guest_info')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('settlement_amount', 10, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('exception_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('exception_type');
            $table->text('exception_message')->nullable();
            $table->text('exception_data')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('handler_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        $scenicSpot = ScenicSpot::create(['name' => '测试景区']);
        $provider = SoftwareProvider::create([
            'name' => '测试服务商',
            'code' => 'TEST',
            'is_active' => true,
        ]);

        $this->hotel = Hotel::create([
            'scenic_spot_id' => $scenicSpot->id,
            'name' => '测试酒店',
            'code' => 'H00013',
        ]);

        $this->roomType = RoomType::create([
            'hotel_id' => $this->hotel->id,
            'name' => '测试房型',
            'code' => 'R00038',
        ]);

        $this->product = Product::create([
            'scenic_spot_id' => $scenicSpot->id,
            'software_provider_id' => $provider->id,
            'name' => '测试产品',
            'code' => '000012',
            'stay_days' => 1,
            'is_active' => true,
        ]);

        OtaPlatformModel::create([
            'name' => '携程',
            'code' => 'ctrip',
            'is_active' => true,
        ]);

        // 直接插入原始行，保证控制器 where('date', 'Y-m-d') 在 sqlite 下可精确匹配
        DB::table('prices')->insert([
            'product_id' => $this->product->id,
            'room_type_id' => $this->roomType->id,
            'date' => self::CHECK_IN_DATE,
            'market_price' => 976,
            'settlement_price' => 897.16,
            'sale_price' => 976,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->inventory = Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => self::CHECK_IN_DATE,
            'total_quantity' => 10,
            'available_quantity' => 10,
            'locked_quantity' => 0,
            'source' => 'manual',
        ]);
    }

    // ==================== InventoryService 行锁锁定 ====================

    public function test_row_lock_service_locks_and_deducts_inventory(): void
    {
        $result = app(InventoryService::class)->lockInventoryForProductWithRowLock(
            $this->product->id,
            $this->roomType->id,
            [self::CHECK_IN_DATE],
            1
        );

        $this->assertTrue($result['success']);

        $this->inventory->refresh();
        $this->assertEquals(9, $this->inventory->available_quantity);
        $this->assertEquals(1, $this->inventory->locked_quantity);
    }

    public function test_row_lock_service_fails_when_inventory_insufficient(): void
    {
        $result = app(InventoryService::class)->lockInventoryForProductWithRowLock(
            $this->product->id,
            $this->roomType->id,
            [self::CHECK_IN_DATE],
            11
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('库存不足', $result['message']);

        $this->inventory->refresh();
        $this->assertEquals(10, $this->inventory->available_quantity);
        $this->assertEquals(0, $this->inventory->locked_quantity);
    }

    public function test_row_lock_service_fails_when_product_control_closed(): void
    {
        ProductRoomInventoryControl::create([
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'date' => self::CHECK_IN_DATE,
            'is_closed' => true,
        ]);

        $result = app(InventoryService::class)->lockInventoryForProductWithRowLock(
            $this->product->id,
            $this->roomType->id,
            [self::CHECK_IN_DATE],
            1
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('产品维度已关闭', $result['message']);

        $this->inventory->refresh();
        $this->assertEquals(10, $this->inventory->available_quantity);
    }

    public function test_row_lock_service_does_not_deduct_any_date_when_one_date_fails(): void
    {
        $secondDate = '2026-08-02';
        Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => $secondDate,
            'total_quantity' => 10,
            'available_quantity' => 0,
            'locked_quantity' => 0,
            'source' => 'manual',
        ]);

        $result = app(InventoryService::class)->lockInventoryForProductWithRowLock(
            $this->product->id,
            $this->roomType->id,
            [self::CHECK_IN_DATE, $secondDate],
            1
        );

        $this->assertFalse($result['success']);

        $this->inventory->refresh();
        $this->assertEquals(10, $this->inventory->available_quantity);
        $this->assertEquals(0, $this->inventory->locked_quantity);
    }

    // ==================== 预下单流程 ====================

    public function test_pre_order_succeeds_and_locks_inventory(): void
    {
        $response = $this->preCreateOrder($this->preOrderPayload('T4-TEST-1'));

        $this->assertPreOrderSuccess($response);

        $order = Order::where('ota_order_no', 'T4-TEST-1')->first();
        $this->assertNotNull($order);

        $this->inventory->refresh();
        $this->assertEquals(9, $this->inventory->available_quantity);
        $this->assertEquals(1, $this->inventory->locked_quantity);

        $this->assertEquals(0, ExceptionOrder::where('order_id', $order->id)->count());
    }

    public function test_two_pre_orders_for_same_room_and_date_both_succeed(): void
    {
        // 复现线上场景：携程同一母订单拆成两笔子单，先后请求同房型同日期
        $first = $this->preCreateOrder($this->preOrderPayload('T4-108862454-1'));
        $second = $this->preCreateOrder($this->preOrderPayload('T4-108862454-2'));

        $this->assertPreOrderSuccess($first);
        $this->assertPreOrderSuccess($second);

        $this->inventory->refresh();
        $this->assertEquals(8, $this->inventory->available_quantity);
        $this->assertEquals(2, $this->inventory->locked_quantity);

        $this->assertEquals(0, ExceptionOrder::count());
    }

    public function test_pre_order_with_insufficient_inventory_creates_exception_order(): void
    {
        $this->inventory->update(['available_quantity' => 0]);

        $response = $this->preCreateOrder($this->preOrderPayload('T4-TEST-EXC'));

        // 按业务规则：库存不足不拒单，返回成功并转异常订单
        $this->assertPreOrderSuccess($response);

        $order = Order::where('ota_order_no', 'T4-TEST-EXC')->first();
        $this->assertNotNull($order);

        $exception = ExceptionOrder::where('order_id', $order->id)->first();
        $this->assertNotNull($exception);
        $this->assertEquals(ExceptionOrderType::INVENTORY_MISMATCH, $exception->exception_type);
        $this->assertTrue((bool) data_get($exception->exception_data, 'inventory_not_locked'));

        // 库存未被扣减
        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->available_quantity);
        $this->assertEquals(0, $this->inventory->locked_quantity);
    }

    public function test_pre_order_with_closed_inventory_creates_exception_order(): void
    {
        $this->inventory->update(['is_closed' => true]);

        $response = $this->preCreateOrder($this->preOrderPayload('T4-TEST-CLOSED'));

        $this->assertPreOrderSuccess($response);

        $order = Order::where('ota_order_no', 'T4-TEST-CLOSED')->first();
        $this->assertNotNull($order);

        $exception = ExceptionOrder::where('order_id', $order->id)->first();
        $this->assertNotNull($exception);
        $this->assertEquals(ExceptionOrderType::INVENTORY_MISMATCH, $exception->exception_type);
    }

    // ==================== 库存释放 ====================

    public function test_release_is_skipped_for_order_marked_inventory_not_locked(): void
    {
        $this->inventory->update(['available_quantity' => 0]);

        $this->preCreateOrder($this->preOrderPayload('T4-TEST-REL-SKIP'));
        $order = Order::where('ota_order_no', 'T4-TEST-REL-SKIP')->first();
        $this->assertNotNull($order);

        $result = $this->callControllerMethod('releaseInventoryForPreOrder', [$order]);

        $this->assertTrue($result['success']);

        // 未锁定过库存，释放被跳过，库存数字不变
        $this->inventory->refresh();
        $this->assertEquals(0, $this->inventory->available_quantity);
        $this->assertEquals(0, $this->inventory->locked_quantity);
    }

    public function test_release_returns_inventory_for_normally_locked_order(): void
    {
        $this->preCreateOrder($this->preOrderPayload('T4-TEST-REL'));
        $order = Order::where('ota_order_no', 'T4-TEST-REL')->first();
        $this->assertNotNull($order);

        $this->inventory->refresh();
        $this->assertEquals(9, $this->inventory->available_quantity);

        $result = $this->callControllerMethod('releaseInventoryForPreOrder', [$order]);

        $this->assertTrue($result['success']);

        $this->inventory->refresh();
        $this->assertEquals(10, $this->inventory->available_quantity);
        $this->assertEquals(0, $this->inventory->locked_quantity);
    }

    // ==================== 辅助方法 ====================

    private function preCreateOrder(array $data): JsonResponse
    {
        return $this->callControllerMethod('handlePreCreateOrder', [$data]);
    }

    private function callControllerMethod(string $method, array $args): mixed
    {
        $controller = app(CtripController::class);
        $reflection = new \ReflectionMethod($controller, $method);

        return $reflection->invokeArgs($controller, $args);
    }

    private function preOrderPayload(string $otaOrderId, int $quantity = 1): array
    {
        return [
            'otaOrderId' => $otaOrderId,
            'items' => [[
                'PLU' => self::PLU,
                'useStartDate' => self::CHECK_IN_DATE,
                'useEndDate' => self::CHECK_IN_DATE,
                'quantity' => $quantity,
                'salePrice' => 976,
                'cost' => 897.16,
                'passengers' => [['name' => '周斌', 'cardNo' => '110101199001011234']],
            ]],
            'contacts' => [['name' => '周斌', 'mobile' => '13800138000']],
        ];
    }

    private function assertPreOrderSuccess(JsonResponse $response): void
    {
        $payload = $response->getData(true);
        $this->assertEquals('0000', data_get($payload, 'header.resultCode'), '预下单应返回成功：' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
