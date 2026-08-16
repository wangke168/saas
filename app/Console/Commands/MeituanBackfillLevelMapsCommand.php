<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\MeituanLevelMap;
use App\Models\Order;
use App\Models\Product;
use App\Services\OTA\MeituanLevelMapService;
use App\Support\MeituanSkuConfirmation;
use Illuminate\Console\Command;

class MeituanBackfillLevelMapsCommand extends Command
{
    protected $signature = 'meituan:backfill-level-maps
                            {--seed-known : 写入产品 000019 已知层级映射}
                            {--from-orders : 从已确认酒店房型的美团订单回填}
                            {--product= : 产品ID（--seed-known 默认 45）}';

    protected $description = '回填美团 levelId 到酒店/房型名映射';

    public function handle(MeituanLevelMapService $service): int
    {
        if (! $this->option('seed-known') && ! $this->option('from-orders')) {
            $this->error('请指定 --seed-known 或 --from-orders');

            return self::FAILURE;
        }

        if ($this->option('seed-known')) {
            $this->seedKnown($service);
        }

        if ($this->option('from-orders')) {
            $this->fromOrders($service);
        }

        return self::SUCCESS;
    }

    private function seedKnown(MeituanLevelMapService $service): void
    {
        $product = $this->resolveSeedProduct();
        if ($product === null) {
            $this->error('未找到产品 000019（可用 --product= 指定产品ID）');

            return;
        }

        $rows = [
            ['level_id' => 198077, 'level_no' => MeituanLevelMap::LEVEL_HOTEL, 'hotel_id' => 13],
            ['level_id' => 198084, 'level_no' => MeituanLevelMap::LEVEL_HOTEL, 'hotel_id' => 22],
            ['level_id' => 198076, 'level_no' => MeituanLevelMap::LEVEL_ROOM, 'hotel_id' => null, 'level_name' => '豪华标准间'],
        ];

        foreach ($rows as $row) {
            $hotelId = $row['hotel_id'];
            $levelName = $row['level_name'] ?? null;
            if ($hotelId !== null) {
                $hotel = Hotel::query()->find($hotelId);
                if ($hotel === null) {
                    $this->warn("酒店 {$hotelId} 不存在，跳过 level_id={$row['level_id']}");

                    continue;
                }
                $levelName = (string) $hotel->name;
            }

            $service->upsertLevel(
                $product,
                (int) $row['level_id'],
                (int) $row['level_no'],
                $hotelId,
                (string) $levelName,
            );
            $this->info("已写入 product={$product->id} level_id={$row['level_id']} {$levelName}");
        }
    }

    private function fromOrders(MeituanLevelMapService $service): void
    {
        $orders = Order::query()
            ->whereNotNull('meituan_level_ids')
            ->whereNotNull('hotel_id')
            ->whereNotNull('room_type_id')
            ->with(['product', 'hotel', 'roomType'])
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            if (MeituanSkuConfirmation::pendingException($order) !== null) {
                continue;
            }

            $product = $order->product;
            $hotel = $order->hotel;
            $roomType = $order->roomType;
            $levelIds = $order->meituan_level_ids;
            if ($product === null || $hotel === null || $roomType === null || ! is_array($levelIds) || $levelIds === []) {
                continue;
            }

            $service->rememberFromMatchedSku($product, $hotel, $roomType, $levelIds);
            $count++;
        }

        $this->info("已从 {$count} 笔订单回填映射");
    }

    private function resolveSeedProduct(): ?Product
    {
        $productId = $this->option('product');
        if ($productId !== null && $productId !== '') {
            $product = Product::query()->find((int) $productId);
            if ($product !== null) {
                return $product;
            }
        }

        $byCode = Product::query()->where('code', '000019')->first();
        if ($byCode !== null) {
            return $byCode;
        }

        return Product::query()->find(45);
    }
}
