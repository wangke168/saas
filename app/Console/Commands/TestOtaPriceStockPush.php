<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OtaPlatform;
use App\Models\OtaProduct;
use App\Models\Product;
use App\Services\OTA\CtripService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestOtaPriceStockPush extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:ota-push {product_id : 产品ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试OTA价库推送功能';

    /**
     * Execute the console command.
     */
    public function handle(CtripService $ctripService): int
    {
        $productId = $this->argument('product_id');
        
        $this->info("开始测试产品 {$productId} 的价库推送功能...");

        // 获取产品
        $product = Product::with(['prices.roomType.hotel'])->find($productId);
        
        if (!$product) {
            $this->error("产品 {$productId} 不存在");
            return Command::FAILURE;
        }

        $this->info("产品信息：");
        $this->line("  - ID: {$product->id}");
        $this->line("  - 名称: {$product->name}");
        $this->line("  - 编码: {$product->code}");
        $this->line("  - 状态: " . ($product->is_active ? '启用' : '禁用'));

        // 检查产品编码
        if (empty($product->code)) {
            $this->error("产品编码为空，请先设置产品编码");
            return Command::FAILURE;
        }

        // 获取产品的所有"产品-酒店-房型"组合
        $prices = $product->prices()->with(['roomType.hotel'])->get();
        
        if ($prices->isEmpty()) {
            $this->error("产品未关联任何价格数据（酒店和房型）");
            return Command::FAILURE;
        }

        $combos = [];
        $seen = [];

        foreach ($prices as $price) {
            $roomType = $price->roomType;
            if (!$roomType) {
                continue;
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                continue;
            }

            // 检查编码
            if (empty($hotel->code) || empty($roomType->code)) {
                $this->warn("跳过：酒店 {$hotel->name} 或房型 {$roomType->name} 编码为空");
                continue;
            }

            $key = "{$hotel->id}_{$roomType->id}";
            if (!isset($seen[$key])) {
                $combos[] = [
                    'hotel' => $hotel,
                    'room_type' => $roomType,
                ];
                $seen[$key] = true;
            }
        }

        if (empty($combos)) {
            $this->error("没有有效的产品-酒店-房型组合（编码为空）");
            return Command::FAILURE;
        }

        $this->info("\n找到 " . count($combos) . " 个组合：");
        foreach ($combos as $index => $combo) {
            $hotel = $combo['hotel'];
            $roomType = $combo['room_type'];
            $ctripCode = $ctripService->generateCtripProductCode(
                $product->code,
                $hotel->code,
                $roomType->code
            );
            $this->line("  " . ($index + 1) . ". 酒店: {$hotel->name} ({$hotel->code}), 房型: {$roomType->name} ({$roomType->code})");
            $this->line("     携程产品编码: {$ctripCode}");
        }

        // 测试推送价格
        $this->info("\n开始测试价格推送...");
        $priceSuccessCount = 0;
        $priceFailCount = 0;

        foreach ($combos as $index => $combo) {
            $hotel = $combo['hotel'];
            $roomType = $combo['room_type'];
            
            $this->line("\n组合 " . ($index + 1) . ": {$hotel->name} - {$roomType->name}");
            
            $result = $ctripService->syncProductPriceByCombo(
                $product,
                $hotel,
                $roomType,
                null,
                'DATE_REQUIRED'
            );

            if ($result['success'] ?? false) {
                $this->info("  ✓ 价格推送成功");
                $priceSuccessCount++;
            } else {
                $this->error("  ✗ 价格推送失败: " . ($result['message'] ?? '未知错误'));
                $priceFailCount++;
            }
        }

        // 测试推送库存
        $this->info("\n开始测试库存推送...");
        $stockSuccessCount = 0;
        $stockFailCount = 0;

        foreach ($combos as $index => $combo) {
            $hotel = $combo['hotel'];
            $roomType = $combo['room_type'];
            
            $this->line("\n组合 " . ($index + 1) . ": {$hotel->name} - {$roomType->name}");
            
            $result = $ctripService->syncProductStockByCombo(
                $product,
                $hotel,
                $roomType,
                null,
                'DATE_REQUIRED'
            );

            if ($result['success'] ?? false) {
                $this->info("  ✓ 库存推送成功");
                $stockSuccessCount++;
            } else {
                $this->error("  ✗ 库存推送失败: " . ($result['message'] ?? '未知错误'));
                $stockFailCount++;
            }
        }

        // 总结
        $this->info("\n" . str_repeat('=', 50));
        $this->info("测试总结：");
        $this->line("价格推送：成功 {$priceSuccessCount} 个，失败 {$priceFailCount} 个");
        $this->line("库存推送：成功 {$stockSuccessCount} 个，失败 {$stockFailCount} 个");

        if ($priceFailCount > 0 || $stockFailCount > 0) {
            $this->warn("\n部分推送失败，请检查日志获取详细信息");
            return Command::FAILURE;
        }

        $this->info("\n✓ 所有推送测试通过！");
        return Command::SUCCESS;
    }
}
