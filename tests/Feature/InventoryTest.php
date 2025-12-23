<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\RoomType;
use App\Models\Hotel;
use App\Models\ScenicSpot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ScenicSpot $scenicSpot;
    protected Hotel $hotel;
    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->scenicSpot = ScenicSpot::factory()->create();
        $this->hotel = Hotel::factory()->create(['scenic_spot_id' => $this->scenicSpot->id]);
        $this->roomType = RoomType::factory()->create(['hotel_id' => $this->hotel->id]);
    }

    /**
     * 测试创建库存
     */
    public function test_can_create_inventory(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/room-types/{$this->roomType->id}/inventories", [
                'date' => now()->addDays(7)->format('Y-m-d'),
                'quantity' => 10,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'room_type_id',
                    'date',
                    'quantity',
                ],
            ]);
    }

    /**
     * 测试更新库存
     */
    public function test_can_update_inventory(): void
    {
        $inventory = Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => now()->addDays(7)->format('Y-m-d'),
            'quantity' => 10,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/inventories/{$inventory->id}", [
                'quantity' => 20,
            ]);

        $response->assertStatus(200);
        
        $inventory->refresh();
        $this->assertEquals(20, $inventory->quantity);
    }

    /**
     * 测试库存查询
     */
    public function test_can_query_inventory_by_date_range(): void
    {
        $startDate = now()->addDays(7)->format('Y-m-d');
        $endDate = now()->addDays(10)->format('Y-m-d');

        Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => $startDate,
            'quantity' => 10,
            'source' => 'manual',
        ]);

        Inventory::create([
            'room_type_id' => $this->roomType->id,
            'date' => $endDate,
            'quantity' => 15,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/room-types/{$this->roomType->id}/inventories?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}

