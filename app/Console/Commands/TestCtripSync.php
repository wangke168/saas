<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OtaPlatform;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\OtaProduct;
use App\Models\Product;
use App\Services\OTA\CtripService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCtripSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ctrip:test-sync 
                            {--product= : 产品ID}
                            {--type= : 同步类型: price|stock|both}
                            {--dates= : 指定日期，多个日期用逗号分隔，如: 2025-12-27,2025-12-28}
                            {--date-type=DATE_REQUIRED : 日期类型: DATE_REQUIRED|DATE_NOT_REQUIRED}
                            {--auto-push : 如果产品未推送到携程，自动创建推送关联}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试携程价格和库存同步';

    public function __construct(
        protected CtripService $ctripService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $productId = $this->option('product');
        $type = $this->option('type') ?: 'both';
        $datesStr = $this->option('dates');
        $dateType = $this->option('date-type') ?: 'DATE_REQUIRED';

        if (!$productId) {
            $this->error('请指定产品ID: --product=1');
            return 1;
        }

        // 查找产品（包括已软删除的，用于测试）
        $product = Product::withTrashed()->find($productId);
        if (!$product) {
            $this->error("产品不存在: {$productId}");
            return 1;
        }

        $dates = null;
        if ($datesStr) {
            $dates = explode(',', $datesStr);
        }

        $this->info("开始测试携程同步 - 产品: {$product->name} (ID: {$product->id})");

        // 检查产品是否已推送到携程
        $ctripPlatform = OtaPlatformModel::where('code', OtaPlatform::CTRIP->value)->first();
        if (!$ctripPlatform) {
            $this->error('携程平台未配置，请先执行配置SQL');
            return 1;
        }

        $otaProduct = OtaProduct::where('product_id', $product->id)
            ->where('ota_platform_id', $ctripPlatform->id)
            ->first();

        if (!$otaProduct) {
            if ($this->option('auto-push')) {
                $this->info('产品未推送到携程，自动创建推送关联...');
                OtaProduct::create([
                    'product_id' => $product->id,
                    'ota_platform_id' => $ctripPlatform->id,
                    'is_active' => true,
                    'pushed_at' => now(),
                ]);
                $this->info('✓ 已创建推送关联');
            } else {
                $this->error('产品未推送到携程');
                $this->line('提示：使用 --auto-push 选项可以自动创建推送关联');
                $this->line('或者通过管理后台在产品详情页点击"推送到OTA平台"');
                return 1;
            }
        }

        // 非指定日期模式下，不传日期参数
        if ($dateType === 'DATE_NOT_REQUIRED') {
            $dates = null;
            $this->info("日期类型: {$dateType} (非指定日期模式，不传日期参数)");
        } else {
            $this->info("日期类型: {$dateType} (指定日期模式)");
            if ($dates) {
                $this->info("指定日期: " . implode(', ', $dates));
            }
        }

        if ($type === 'price' || $type === 'both') {
            $this->info('同步价格...');
            $result = $this->ctripService->syncProductPrice($product, $dates, $dateType);
            
            if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
                $this->info('✓ 价格同步成功');
            } else {
                $this->error('✗ 价格同步失败: ' . ($result['header']['resultMessage'] ?? '未知错误'));
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }

        if ($type === 'stock' || $type === 'both') {
            $this->info('同步库存...');
            $result = $this->ctripService->syncProductStock($product, $dates, $dateType);
            
            if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
                $this->info('✓ 库存同步成功');
            } else {
                $this->error('✗ 库存同步失败: ' . ($result['header']['resultMessage'] ?? '未知错误'));
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }

        return 0;
    }
}

