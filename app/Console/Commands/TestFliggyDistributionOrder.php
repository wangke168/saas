<?php

namespace App\Console\Commands;

use App\Http\Client\FliggyDistributionClient;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Models\ScenicSpot;
use App\Models\SoftwareProvider;
use App\Services\FliggyMappingService;
use App\Services\FliggyOrderDataBuilder;
use Illuminate\Console\Command;

/**
 * 测试飞猪分销系统订单接口的命令
 * 
 * 使用方法：
 * php artisan test:fliggy-order --validate --order-id=123
 * php artisan test:fliggy-order --create --order-id=123
 * php artisan test:fliggy-order --cancel --fliggy-order-id=FLIGGY123
 * php artisan test:fliggy-order --query --fliggy-order-id=FLIGGY123
 * php artisan test:fliggy-order --refund --fliggy-order-id=FLIGGY123
 * php artisan test:fliggy-order --all --order-id=123
 */
class TestFliggyDistributionOrder extends Command
{
    protected $signature = 'test:fliggy-order 
                            {--order-id= : 指定订单ID进行测试}
                            {--fliggy-order-id= : 指定飞猪订单号进行测试}
                            {--validate : 测试订单验证}
                            {--create : 测试订单创建}
                            {--cancel : 测试订单取消}
                            {--query : 测试订单查询}
                            {--refund : 测试订单退款}
                            {--all : 测试所有接口}';

    protected $description = '测试飞猪分销系统的订单接口';

    public function handle(): int
    {
        $this->info('========================================');
        $this->info('飞猪分销系统订单接口测试');
        $this->info('========================================');
        $this->newLine();

        // 获取配置
        $config = $this->getConfig();
        if (!$config) {
            $this->error('飞猪分销系统配置不存在，请先配置');
            return 1;
        }

        try {
            $client = new FliggyDistributionClient($config);
        } catch (\Exception $e) {
            $this->error('创建客户端失败：' . $e->getMessage());
            return 1;
        }

        $testAll = $this->option('all');
        $orderId = $this->option('order-id');
        $fliggyOrderId = $this->option('fliggy-order-id');

        // 测试1：订单验证
        if ($testAll || $this->option('validate')) {
            if (!$orderId) {
                $orderId = $this->ask('请输入订单ID');
            }
            if ($orderId) {
                $order = Order::with(['product', 'hotel', 'roomType'])->find($orderId);
                if ($order) {
                    $this->testValidateOrder($client, $order, $config);
                } else {
                    $this->error("订单不存在: {$orderId}");
                }
            }
        }

        // 测试2：订单创建
        if ($testAll || $this->option('create')) {
            if (!$orderId) {
                $orderId = $this->ask('请输入订单ID');
            }
            if ($orderId) {
                $order = Order::with(['product', 'hotel', 'roomType'])->find($orderId);
                if ($order) {
                    $this->testCreateOrder($client, $order, $config);
                } else {
                    $this->error("订单不存在: {$orderId}");
                }
            }
        }

        // 测试3：订单查询
        if ($testAll || $this->option('query')) {
            if (!$fliggyOrderId) {
                $fliggyOrderId = $this->ask('请输入飞猪订单号（或外部订单号）');
            }
            if ($fliggyOrderId) {
                $outOrderId = $this->ask('请输入外部订单号（可选，按回车跳过）');
                $this->testSearchOrder($client, $fliggyOrderId, $outOrderId ?: null);
            }
        }

        // 测试4：订单取消
        if ($testAll || $this->option('cancel')) {
            if (!$fliggyOrderId) {
                $fliggyOrderId = $this->ask('请输入飞猪订单号（或外部订单号）');
            }
            if ($fliggyOrderId) {
                $outOrderId = $this->ask('请输入外部订单号（可选，按回车跳过）');
                $reason = $this->ask('请输入取消原因', '测试取消');
                $this->testCancelOrder($client, $fliggyOrderId, $outOrderId ?: null, $reason);
            }
        }

        // 测试5：订单退款
        if ($testAll || $this->option('refund')) {
            if (!$fliggyOrderId) {
                $fliggyOrderId = $this->ask('请输入飞猪订单号');
            }
            if ($fliggyOrderId) {
                $refundReason = $this->ask('请输入退款原因', '测试退款');
                $remark = $this->ask('请输入备注（可选，按回车跳过）');
                $this->testRefundOrder($client, $fliggyOrderId, $refundReason, $remark ?: '');
            }
        }

        $this->newLine();
        $this->info('测试完成！');
        return 0;
    }

    /**
     * 获取飞猪分销系统配置
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
     * 测试1：订单验证
     */
    protected function testValidateOrder(FliggyDistributionClient $client, Order $order, ResourceConfig $config): void
    {
        $this->info("\n=== 测试1：订单验证 ===");
        $this->displayOrderInfo($order);

        try {
            // 获取飞猪产品ID
            $mappingService = app(FliggyMappingService::class);
            $fliggyProductId = $mappingService->getFliggyProductId(
                $order->product_id,
                $order->hotel_id,
                $order->room_type_id
            );

            if (!$fliggyProductId) {
                $this->error('未找到飞猪产品映射关系');
                $this->warn('提示：请先在 product_fliggy_mappings 表中创建映射关系');
                return;
            }

            $this->info("飞猪产品ID: {$fliggyProductId}");

            // 构建订单数据
            $orderDataBuilder = app(FliggyOrderDataBuilder::class);
            $orderDataBuilder->setConfig($config);
            $orderData = $orderDataBuilder->buildOrderData($order, $fliggyProductId);

            $this->line("调用 validateOrder()...");
            $result = $client->validateOrder($orderData);

            $this->displayResult($result);

        } catch (\Exception $e) {
            $this->error('❌ 订单验证异常: ' . $e->getMessage());
            $this->error("  堆栈: " . $e->getTraceAsString());
        }
    }

    /**
     * 测试2：订单创建
     */
    protected function testCreateOrder(FliggyDistributionClient $client, Order $order, ResourceConfig $config): void
    {
        $this->info("\n=== 测试2：订单创建 ===");
        $this->displayOrderInfo($order);

        try {
            // 获取飞猪产品ID
            $mappingService = app(FliggyMappingService::class);
            $fliggyProductId = $mappingService->getFliggyProductId(
                $order->product_id,
                $order->hotel_id,
                $order->room_type_id
            );

            if (!$fliggyProductId) {
                $this->error('未找到飞猪产品映射关系');
                $this->warn('提示：请先在 product_fliggy_mappings 表中创建映射关系');
                return;
            }

            $this->info("飞猪产品ID: {$fliggyProductId}");

            // 构建订单数据
            $orderDataBuilder = app(FliggyOrderDataBuilder::class);
            $orderDataBuilder->setConfig($config);
            $orderData = $orderDataBuilder->buildOrderData($order, $fliggyProductId);

            // 先验证
            $this->line("步骤1: 调用 validateOrder()...");
            $validateResult = $client->validateOrder($orderData);

            if (!($validateResult['success'] ?? false)) {
                $this->error('❌ 订单验证失败，无法创建订单');
                $this->displayResult($validateResult);
                return;
            }

            $this->info('✓ 订单验证通过');

            // 再创建
            $this->line("步骤2: 调用 createOrder()...");
            $result = $client->createOrder($orderData);

            $this->displayResult($result);

            if ($result['success'] ?? false) {
                $orderIds = $result['data']['orderIds'] ?? [];
                if (!empty($orderIds)) {
                    $fliggyOrderId = (string)$orderIds[0];
                    $this->info("飞猪订单号: {$fliggyOrderId}");
                    
                    // 更新订单
                    $order->update([
                        'resource_order_no' => $fliggyOrderId,
                    ]);
                    $this->info('✓ 订单 resource_order_no 已更新');
                }
            }

        } catch (\Exception $e) {
            $this->error('❌ 订单创建异常: ' . $e->getMessage());
            $this->error("  堆栈: " . $e->getTraceAsString());
        }
    }

    /**
     * 测试3：订单查询
     */
    protected function testSearchOrder(FliggyDistributionClient $client, string $fliggyOrderId, ?string $outOrderId = null): void
    {
        $this->info("\n=== 测试3：订单查询 ===");
        $this->line("飞猪订单号: {$fliggyOrderId}");
        if ($outOrderId) {
            $this->line("外部订单号: {$outOrderId}");
        }

        try {
            $this->line("调用 searchOrder()...");
            $result = $client->searchOrder($fliggyOrderId, $outOrderId);

            $this->displayResult($result);

            if ($result['success'] ?? false) {
                $orderData = $result['data'] ?? [];
                if (!empty($orderData)) {
                    $this->info("\n订单详情：");
                    $this->line("  订单状态: " . ($orderData['orderStatus'] ?? ''));
                    $this->line("  订单金额: " . (isset($orderData['totalPrice']) ? ($orderData['totalPrice'] / 100) . ' 元' : ''));
                    $this->line("  创建时间: " . ($orderData['createTime'] ?? ''));
                    $this->line("  支付时间: " . ($orderData['payTime'] ?? ''));
                    if (isset($orderData['codeInfos']) && !empty($orderData['codeInfos'])) {
                        $this->line("  码信息数量: " . count($orderData['codeInfos']));
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error('❌ 订单查询异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试4：订单取消
     */
    protected function testCancelOrder(FliggyDistributionClient $client, string $fliggyOrderId, ?string $outOrderId = null, string $reason = ''): void
    {
        $this->info("\n=== 测试4：订单取消 ===");
        $this->line("飞猪订单号: {$fliggyOrderId}");
        if ($outOrderId) {
            $this->line("外部订单号: {$outOrderId}");
        }
        $this->line("取消原因: {$reason}");

        try {
            $this->line("调用 cancelOrder()...");
            $result = $client->cancelOrder($fliggyOrderId, $outOrderId, $reason);

            $this->displayResult($result);

        } catch (\Exception $e) {
            $this->error('❌ 订单取消异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试5：订单退款
     */
    protected function testRefundOrder(FliggyDistributionClient $client, string $fliggyOrderId, string $refundReason, string $remark = ''): void
    {
        $this->info("\n=== 测试5：订单退款申请 ===");
        $this->line("飞猪订单号: {$fliggyOrderId}");
        $this->line("退款原因: {$refundReason}");
        if ($remark) {
            $this->line("备注: {$remark}");
        }

        try {
            $this->line("调用 refundOrder()...");
            $result = $client->refundOrder($fliggyOrderId, $refundReason, $remark);

            $this->displayResult($result);

            if ($result['success'] ?? false) {
                $this->info("\n提示：可以调用 searchRefundOrder() 查询退款状态");
            }

        } catch (\Exception $e) {
            $this->error('❌ 订单退款异常: ' . $e->getMessage());
        }
    }

    /**
     * 显示订单信息
     */
    protected function displayOrderInfo(Order $order): void
    {
        $this->info('[订单信息]');
        $this->line("  订单ID: {$order->id}");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  产品ID: {$order->product_id}");
        $this->line("  酒店ID: " . ($order->hotel_id ?? '无'));
        $this->line("  房型ID: " . ($order->room_type_id ?? '无'));
        $this->line("  订单状态: {$order->status->value}");
        $this->line("  资源方订单号: " . ($order->resource_order_no ?? '无'));
        $this->line("  入住日期: " . ($order->check_in_date ?? '无'));
        $this->line("  订单金额: " . ($order->total_amount ?? 0) . ' 元');
        $this->line("  联系人: " . ($order->contact_name ?? '无'));
        $this->line("  联系电话: " . ($order->contact_phone ?? '无'));
    }

    /**
     * 显示测试结果
     */
    protected function displayResult(array $result): void
    {
        $this->newLine();
        if ($result['success'] ?? false) {
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

            // 提供调试建议
            if (in_array($result['code'] ?? '', ['5001', '5002'])) {
                $this->warn("\n调试建议：");
                $this->line("  1. 检查 distributorId 是否正确");
                $this->line("  2. 检查 privateKey 格式是否正确");
                $this->line("  3. 检查签名公式是否正确");
                $this->line("  4. 查看日志文件获取详细信息：");
                $this->line("     tail -f storage/logs/laravel.log | grep '飞猪'");
            }

            if (isset($result['data'])) {
                $this->line("\n完整响应: " . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }
}

