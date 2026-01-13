<?php

namespace App\Console\Commands;

use App\Http\Client\FliggyDistributionClient;
use App\Models\ResourceConfig;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 测试飞猪分销系统产品接口的命令
 * 
 * 使用方法：
 * php artisan test:fliggy-product [--page] [--product-id=] [--price-stock]
 */
class TestFliggyDistributionProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:fliggy-product 
                            {--page : 测试分页查询产品列表}
                            {--product-id= : 指定产品ID进行测试}
                            {--price-stock : 测试价格/库存查询}
                            {--all : 测试所有接口}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试飞猪分销系统的产品接口';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('开始测试飞猪分销系统产品接口...');
        
        // 获取配置
        $config = $this->getConfig();
        if (!$config) {
            $this->error('飞猪分销系统配置不存在，请先配置');
            return;
        }
        
        try {
            $client = new FliggyDistributionClient($config);
        } catch (\Exception $e) {
            $this->error('创建客户端失败：' . $e->getMessage());
            return;
        }
        
        $testAll = $this->option('all');
        
        // 测试1：分页查询
        if ($testAll || $this->option('page')) {
            $this->testQueryProductBaseInfoByPage($client);
        }
        
        // 测试2和3：产品详情（需要产品ID）
        if ($testAll || $this->option('product-id')) {
            $productId = $this->option('product-id');
            if (!$productId) {
                $productId = $this->ask('请输入产品ID（或按回车跳过）');
            }
            
            if ($productId) {
                $this->testQueryProductBaseInfoByIds($client, [$productId]);
                $this->testQueryProductDetailInfo($client, $productId);
            }
        }
        
        // 测试4：价格/库存查询
        if ($testAll || $this->option('price-stock')) {
            $productId = $this->option('product-id');
            if (!$productId) {
                $productId = $this->ask('请输入产品ID（或按回车跳过）');
            }
            
            if ($productId) {
                $this->testQueryProductPriceStock($client, $productId);
            }
        }
        
        $this->info('测试完成！');
    }

    /**
     * 获取飞猪分销系统配置
     * 
     * @return ResourceConfig|null
     */
    protected function getConfig(): ?ResourceConfig
    {
        // 从环境变量获取配置
        $distributorId = env('FLIGGY_DISTRIBUTION_ID');
        $privateKey = env('FLIGGY_DISTRIBUTION_PRIVATE_KEY');
        $apiUrl = env('FLIGGY_DISTRIBUTION_API_URL', 'https://pre-api.alitrip.alibaba.com');
        
        if (empty($distributorId) || empty($privateKey)) {
            $this->warn('环境变量未配置，尝试从数据库获取配置...');
            
            // 尝试从数据库获取
            $provider = SoftwareProvider::where('api_type', 'fliggy_distribution')->first();
            if (!$provider) {
                return null;
            }
            
            $config = ResourceConfig::where('software_provider_id', $provider->id)->first();
            if (!$config) {
                return null;
            }
            
            return $config;
        }
        
        // 从环境变量创建临时配置
        $provider = SoftwareProvider::firstOrCreate(
            ['api_type' => 'fliggy_distribution'],
            [
                'name' => '飞猪分销系统',
                'code' => 'FLIGGY_DISTRIBUTION',
                'api_url' => $apiUrl,
                'is_active' => true,
            ]
        );
        
        $scenicSpot = ScenicSpot::first();
        if (!$scenicSpot) {
            $this->error('请先创建景区');
            return null;
        }
        
        $config = new ResourceConfig();
        $config->software_provider_id = $provider->id;
        $config->scenic_spot_id = $scenicSpot->id;
        $config->api_url = $apiUrl;
        $config->extra_config = [
            'distributor_id' => $distributorId,
            'private_key' => $privateKey,
        ];
        
        return $config;
    }

    /**
     * 测试1：分页查询产品列表
     * 
     * @param FliggyDistributionClient $client
     * @return void
     */
    protected function testQueryProductBaseInfoByPage(FliggyDistributionClient $client): void
    {
        $this->info("\n=== 测试1：批量获取产品基本信息（分页） ===");
        
        $pageNo = $this->ask('请输入页码（默认1）', 1);
        $pageSize = $this->ask('请输入页大小（默认20，最大100）', 20);
        
        $result = $client->queryProductBaseInfoByPage((int)$pageNo, (int)$pageSize);
        
        $this->displayResult($result);
    }

    /**
     * 测试2：按ID查询产品
     * 
     * @param FliggyDistributionClient $client
     * @param array $productIds
     * @return void
     */
    protected function testQueryProductBaseInfoByIds(FliggyDistributionClient $client, array $productIds): void
    {
        $this->info("\n=== 测试2：批量获取产品基本信息（按ID） ===");
        
        $result = $client->queryProductBaseInfoByIds($productIds);
        
        $this->displayResult($result);
    }

    /**
     * 测试3：获取产品详情
     * 
     * @param FliggyDistributionClient $client
     * @param string $productId
     * @return void
     */
    protected function testQueryProductDetailInfo(FliggyDistributionClient $client, string $productId): void
    {
        $this->info("\n=== 测试3：获取产品详情（单体） ===");
        
        $result = $client->queryProductDetailInfo($productId);
        
        $this->displayResult($result);
        
        // 如果有数据，显示产品详情的关键信息
        if ($result['success'] && isset($result['data']['productBaseInfo'])) {
            $productInfo = $result['data']['productBaseInfo'];
            $this->info("\n产品信息：");
            $this->line("  产品ID: " . ($productInfo['productId'] ?? ''));
            $this->line("  产品名称: " . ($productInfo['productName'] ?? ''));
            
            if (isset($result['data']['elementList'])) {
                $this->info("\n产品元素：");
                foreach ($result['data']['elementList'] as $element) {
                    $elementType = $element['elementType'] ?? '';
                    $typeName = match($elementType) {
                        1 => '门票',
                        2 => '酒店',
                        3 => '交通',
                        4 => '餐饮',
                        5 => '特色活动',
                        default => '未知',
                    };
                    $this->line("  - {$typeName}");
                }
            }
        }
    }

    /**
     * 测试4：查询价格/库存
     * 
     * @param FliggyDistributionClient $client
     * @param string $productId
     * @return void
     */
    protected function testQueryProductPriceStock(FliggyDistributionClient $client, string $productId): void
    {
        $this->info("\n=== 测试4：批量获取价格/库存 ===");
        
        $withTimeRange = $this->confirm('是否指定时间范围？', false);
        
        $beginTime = null;
        $endTime = null;
        
        if ($withTimeRange) {
            $beginDate = $this->ask('请输入开始日期（格式：Y-m-d，如：2024-01-01）');
            $endDate = $this->ask('请输入结束日期（格式：Y-m-d，如：2024-01-31）');
            
            if ($beginDate) {
                $beginTime = strtotime($beginDate) * 1000;
            }
            if ($endDate) {
                $endTime = strtotime($endDate) * 1000;
            }
        }
        
        $result = $client->queryProductPriceStock($productId, $beginTime, $endTime);
        
        $this->displayResult($result);
        
        // 如果有数据，显示价格/库存信息
        if ($result['success'] && isset($result['data']['calendarStock'])) {
            $this->info("\n价格/库存信息：");
            $stocks = $result['data']['calendarStock'];
            $this->line("  共 " . count($stocks) . " 条记录");
            
            // 显示前5条
            foreach (array_slice($stocks, 0, 5) as $stock) {
                $date = isset($stock['date']) ? date('Y-m-d', $stock['date'] / 1000) : '';
                $price = isset($stock['distributionPrice']) ? ($stock['distributionPrice'] / 100) : 0;
                $stockNum = $stock['stock'] ?? 0;
                $this->line("  {$date}: 价格 {$price} 元，库存 {$stockNum}");
            }
            
            if (count($stocks) > 5) {
                $this->line("  ... 还有 " . (count($stocks) - 5) . " 条记录");
            }
        }
    }

    /**
     * 显示测试结果
     * 
     * @param array $result
     * @return void
     */
    protected function displayResult(array $result): void
    {
        if ($result['success']) {
            $this->info("✓ 请求成功");
            $this->line("  响应码: " . ($result['code'] ?? ''));
            $this->line("  消息: " . ($result['message'] ?? ''));
            
            if (isset($result['data'])) {
                $this->line("  数据: " . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        } else {
            $this->error("✗ 请求失败");
            $this->line("  响应码: " . ($result['code'] ?? ''));
            $this->line("  错误信息: " . ($result['message'] ?? '未知错误'));
            
            // 如果是 4003 或其他错误，提供调试建议
            if (($result['code'] ?? '') == '4003') {
                $this->warn("\n调试建议：");
                $this->line("  1. 检查 distributorId 是否正确");
                $this->line("  2. 检查 privateKey 格式是否正确");
                $this->line("  3. 检查签名公式是否正确（分页接口应该是 distributorId_timestamp_）");
                $this->line("  4. 查看日志文件获取详细的签名信息：");
                $this->line("     tail -f storage/logs/laravel.log | grep '飞猪'");
            }
            
            if (isset($result['data'])) {
                $this->line("\n完整响应: " . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }
}

