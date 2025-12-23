<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\ScenicSpot;
use App\Models\User;
use App\Models\Inventory;
use App\Models\OtaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderProcessTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ScenicSpot $scenicSpot;
    protected Hotel $hotel;
    protected RoomType $roomType;
    protected Product $product;
    protected OtaPlatform $otaPlatform;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建测试数据
        $this->user = User::factory()->create();
        $this->scenicSpot = ScenicSpot::factory()->create();
        $this->hotel = Hotel::factory()->create(['scenic_spot_id' => $this->scenicSpot->id]);
        $this->roomType = RoomType::factory()->create(['hotel_id' => $this->hotel->id]);
        $this->product = Product::factory()->create([
            'scenic_spot_id' => $this->scenicSpot->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
        ]);
        $this->otaPlatform = OtaPlatform::factory()->create();
    }

    /**
     * 测试订单创建流程
     */
    public function test_can_create_order(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/orders', [
                'ota_platform_id' => $this->otaPlatform->id,
                'product_id' => $this->product->id,
                'hotel_id' => $this->hotel->id,
                'room_type_id' => $this->roomType->id,
                'status' => OrderStatus::PAID_PENDING->value,
                'check_in_date' => now()->addDays(7)->format('Y-m-d'),
                'check_out_date' => now()->addDays(9)->format('Y-m-d'),
                'room_count' => 1,
                'guest_count' => 2,
                'contact_name' => '测试用户',
                'contact_phone' => '13800138000',
                'total_amount' => 1000.00,
                'settlement_amount' => 800.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'order_no',
                    'status',
                    'total_amount',
                ],
            ]);
    }

    /**
     * 测试库存扣减
     */
    public function test_inventory_is_decremented_when_order_created(): void
    {
        $checkInDate = now()->addDays(7)->format('Y-m-d');
        
        // 创建库存
        Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkInDate,
            'quantity' => 10,
            'source' => 'manual',
        ]);

        $initialQuantity = Inventory::where('room_type_id', $this->roomType->id)
            ->where('date', $checkInDate)
            ->first()
            ->quantity;

        // 创建订单
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/orders', [
                'ota_platform_id' => $this->otaPlatform->id,
                'product_id' => $this->product->id,
                'hotel_id' => $this->hotel->id,
                'room_type_id' => $this->roomType->id,
                'status' => OrderStatus::PAID_PENDING->value,
                'check_in_date' => $checkInDate,
                'check_out_date' => now()->addDays(9)->format('Y-m-d'),
                'room_count' => 2,
                'guest_count' => 4,
                'contact_name' => '测试用户',
                'contact_phone' => '13800138000',
                'total_amount' => 2000.00,
                'settlement_amount' => 1600.00,
            ]);

        $finalQuantity = Inventory::where('room_type_id', $this->roomType->id)
            ->where('date', $checkInDate)
            ->first()
            ->quantity;

        $this->assertEquals($initialQuantity - 2, $finalQuantity);
    }

    /**
     * 测试库存不足时订单创建失败
     */
    public function test_order_creation_fails_when_inventory_insufficient(): void
    {
        $checkInDate = now()->addDays(7)->format('Y-m-d');
        
        // 创建少量库存
        Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => $checkInDate,
            'quantity' => 1,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/orders', [
                'ota_platform_id' => $this->otaPlatform->id,
                'product_id' => $this->product->id,
                'hotel_id' => $this->hotel->id,
                'room_type_id' => $this->roomType->id,
                'status' => OrderStatus::PAID_PENDING->value,
                'check_in_date' => $checkInDate,
                'check_out_date' => now()->addDays(9)->format('Y-m-d'),
                'room_count' => 2, // 超过库存
                'guest_count' => 4,
                'contact_name' => '测试用户',
                'contact_phone' => '13800138000',
                'total_amount' => 2000.00,
                'settlement_amount' => 1600.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['room_count']);
    }

    /**
     * 测试订单状态流转
     */
    public function test_order_status_can_be_updated(): void
    {
        $order = Order::factory()->create([
            'ota_platform_id' => $this->otaPlatform->id,
            'product_id' => $this->product->id,
            'hotel_id' => $this->hotel->id,
            'room_type_id' => $this->roomType->id,
            'status' => OrderStatus::PAID_PENDING->value,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/orders/{$order->id}", [
                'status' => OrderStatus::CONFIRMING->value,
            ]);

        $response->assertStatus(200);
        
        $order->refresh();
        $this->assertEquals(OrderStatus::CONFIRMING->value, $order->status);
    }
}

