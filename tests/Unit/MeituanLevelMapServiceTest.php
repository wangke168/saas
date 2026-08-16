<?php

namespace Tests\Unit;

use App\Services\OTA\MeituanLevelMapService;
use App\Services\ProductService;
use PHPUnit\Framework\TestCase;

class MeituanLevelMapServiceTest extends TestCase
{
    private function service(): MeituanLevelMapService
    {
        return new MeituanLevelMapService($this->createMock(ProductService::class));
    }

    public function test_filter_by_room_name_and_price_distinguishes_same_price_different_names(): void
    {
        $candidates = [
            [
                'hotel_id' => 22,
                'hotel_name' => '旅游大厦',
                'room_type_id' => 44,
                'room_type_name' => '豪华标准间',
                'sale_price' => 866.0,
            ],
            [
                'hotel_id' => 22,
                'hotel_name' => '旅游大厦',
                'room_type_id' => 45,
                'room_type_name' => '豪华大床房',
                'sale_price' => 866.0,
            ],
            [
                'hotel_id' => 15,
                'hotel_name' => '京华大酒店',
                'room_type_id' => 30,
                'room_type_name' => '标准间',
                'sale_price' => 866.0,
            ],
            [
                'hotel_id' => 10,
                'hotel_name' => '丰景嘉丽',
                'room_type_id' => 13,
                'room_type_name' => '豪华标准间',
                'sale_price' => 1066.0,
            ],
        ];

        $matched = $this->service()->filterByRoomNameAndPrice($candidates, '豪华标准间', 866);

        $this->assertCount(1, $matched);
        $this->assertSame(22, $matched[0]['hotel_id']);
        $this->assertSame(44, $matched[0]['room_type_id']);
    }

    public function test_parse_level_ids_uses_first_hotel_and_last_room(): void
    {
        $parsed = $this->service()->parseLevelIds([198084, 198076]);

        $this->assertSame([
            'hotel_level_id' => 198084,
            'room_level_id' => 198076,
        ], $parsed);
    }
}
