<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OTA\CtripService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCtripCancelOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:ctrip-cancel-order 
                            {--order= : 使用订单ID测试}
                            {--ota-order-id= : OTA订单号（手动模式）}
                            {--supplier-order-id= : 供应商订单号（手动模式）}
                            {--item-id= : 订单项ID（手动模式，可选）}
                            {--confirm-code= : 确认结果码（默认：0000）}
                            {--confirm-message= : 确认结果消息（默认：确认成功）}
                            {--voucher-id= : 凭证ID（可选，如果有凭证需要取消）}
                            {--json : 以JSON格式输出}
                            {--show-body : 显示完整的请求body数据}
                            {--save-request= : 保存请求数据到文件（JSON格式）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试携程订单取消确认接口 - CancelOrderConfirm';

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
        $this->info('========================================');
        $this->info('携程订单取消确认接口测试 - CancelOrderConfirm');
        $this->info('========================================');
        $this->newLine();

        $orderId = $this->option('order');
        
        if ($orderId) {
            // 使用订单ID模式
            return $this->testWithOrderId((int)$orderId);
        } else {
            // 手动输入模式
            return $this->testWithManualInput();
        }
    }

    /**
     * 使用订单ID测试
     */
    protected function testWithOrderId(int $orderId): int
    {
        $this->info("[模式] 使用订单ID测试");
        $this->info("订单ID: {$orderId}");
        $this->newLine();

        $order = Order::with(['product', 'otaPlatform'])->find($orderId);
        
        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return 1;
        }

        if (!$order->ota_order_no) {
            $this->error("订单没有OTA订单号");
            return 1;
        }

        $this->info("[订单信息]");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  OTA订单号: {$order->ota_order_no}");
        $this->line("  资源方订单号: " . ($order->resource_order_no ?: '(未设置)'));
        $this->line("  订单项ID: " . ($order->ctrip_item_id ?: '(未设置)'));
        $this->line("  订单状态: {$order->status->value}");
        if ($order->product) {
            $this->line("  产品: {$order->product->name} (ID: {$order->product_id})");
        }
        $this->newLine();

        // 显示配置信息
        $this->displayConfig();

        // 调用携程服务
        $this->info("[开始测试]");
        $this->line("调用 CtripService::confirmCancelOrder()...");
        $this->newLine();

        try {
            $result = $this->ctripService->confirmCancelOrder($order);
            
            $this->displayResult($result, $order);
            
            return $this->isSuccess($result) ? 0 : 1;
        } catch (\Exception $e) {
            $this->error("[异常]");
            $this->error("  " . $e->getMessage());
            $this->error("  文件: " . $e->getFile() . ":" . $e->getLine());
            $this->newLine();
            $this->line("详细错误信息请查看日志文件: storage/logs/laravel.log");
            
            return 1;
        }
    }

    /**
     * 手动输入模式测试
     */
    protected function testWithManualInput(): int
    {
        $this->info("[模式] 手动输入测试");
        $this->newLine();

        // 收集参数
        $otaOrderId = $this->option('ota-order-id') ?: $this->ask('OTA订单号（携程订单号）', 'TEST-ORDER-' . time());
        $supplierOrderId = $this->option('supplier-order-id') ?: $this->ask('供应商订单号（我们的订单号）', 'ORD' . date('YmdHis'));
        $itemId = $this->option('item-id') ?: $supplierOrderId;
        $confirmCode = $this->option('confirm-code') ?: '0000';
        $confirmMessage = $this->option('confirm-message') ?: '确认成功';
        $voucherId = $this->option('voucher-id');

        $this->newLine();
        $this->info("[测试数据]");
        $this->line("  OTA订单号: {$otaOrderId}");
        $this->line("  供应商订单号: {$supplierOrderId}");
        $this->line("  订单项ID: {$itemId}");
        $this->line("  确认结果码: {$confirmCode}");
        $this->line("  确认结果消息: {$confirmMessage}");
        if ($voucherId) {
            $this->line("  凭证ID: {$voucherId}");
        }
        $this->newLine();

        // 显示配置信息
        $this->displayConfig();

        // 构建 items 数组
        $item = [
            'itemId' => $itemId,
        ];
        
        // 如果有凭证ID，添加到 item 中
        if ($voucherId) {
            $item['vouchers'] = [
                [
                    'voucherId' => $voucherId,
                ]
            ];
        }
        
        $items = [$item];

        // 调用携程客户端
        $this->info("[开始测试]");
        $this->line("调用 CtripClient::confirmCancelOrder()...");
        $this->newLine();

        try {
            // 使用反射获取 client
            $client = $this->getClient();
            
            $result = $client->confirmCancelOrder(
                $otaOrderId,
                $supplierOrderId,
                $confirmCode,
                $confirmMessage,
                $items
            );
            
            $this->displayResult($result, null, [
                'otaOrderId' => $otaOrderId,
                'supplierOrderId' => $supplierOrderId,
                'itemId' => $itemId,
                'confirmCode' => $confirmCode,
                'confirmMessage' => $confirmMessage,
                'voucherId' => $voucherId,
                'items' => $items,
            ]);
            
            // 保存请求数据（如果指定）
            if ($saveFile = $this->option('save-request')) {
                $this->saveRequestData($saveFile, [
                    'otaOrderId' => $otaOrderId,
                    'supplierOrderId' => $supplierOrderId,
                    'itemId' => $itemId,
                    'confirmCode' => $confirmCode,
                    'confirmMessage' => $confirmMessage,
                    'voucherId' => $voucherId,
                    'items' => $items,
                ], $result);
            }
            
            return $this->isSuccess($result) ? 0 : 1;
        } catch (\Exception $e) {
            $this->error("[异常]");
            $this->error("  " . $e->getMessage());
            $this->error("  文件: " . $e->getFile() . ":" . $e->getLine());
            $this->newLine();
            $this->line("详细错误信息请查看日志文件: storage/logs/laravel.log");
            
            return 1;
        }
    }

    /**
     * 显示配置信息
     */
    protected function displayConfig(): void
    {
        $this->info("[配置信息]");
        
        $url = env('CTRIP_ORDER_API_URL');
        if (!$url) {
            $url = 'https://ttdentry.ctrip.com/ttd-connect-orderentryapi/supplier/order/notice.do';
        }
        $this->line("  API URL: {$url}");
        
        $accountId = env('CTRIP_ACCOUNT_ID', '(未设置)');
        $this->line("  Account ID: {$accountId}");
        $this->line("  Service Name: CancelOrderConfirm");
        $this->newLine();
    }

    /**
     * 显示测试结果
     */
    protected function displayResult(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $this->info("[测试结果]");
        $this->newLine();

        if ($this->isSuccess($result)) {
            $this->info("  ✓ 成功");
            $this->newLine();
            
            if ($this->option('json')) {
                $this->line("  完整响应数据:");
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->displayResponseData($result);
            }
        } else {
            $this->error("  ✗ 失败");
            $this->newLine();
            
            $this->displayErrorInfo($result);
            $this->newLine();
            $this->info("[诊断信息]");
            $this->diagnoseError($result, $order, $manualData);
        }
        
        $this->newLine();
        $this->line("详细日志请查看: storage/logs/laravel.log");
        
        // 如果指定了显示body，从日志中提取并显示
        if ($this->option('show-body')) {
            $this->displayBodyDataFromLogs();
        }
    }

    /**
     * 显示响应数据
     */
    protected function displayResponseData(array $result): void
    {
        if (isset($result['header'])) {
            $header = $result['header'];
            $this->line("  响应Header:");
            $this->line("    resultCode: " . ($header['resultCode'] ?? 'N/A'));
            $this->line("    resultMessage: " . ($header['resultMessage'] ?? 'N/A'));
            $this->line("    version: " . ($header['version'] ?? 'N/A'));
        }
        
        if (isset($result['body'])) {
            $this->newLine();
            $this->line("  响应Body:");
            if (is_array($result['body'])) {
                $this->line(json_encode($result['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line("    " . $result['body']);
            }
        }
    }

    /**
     * 显示错误信息
     */
    protected function displayErrorInfo(array $result): void
    {
        $errorMessage = '未知错误';
        
        if (isset($result['header']['resultMessage'])) {
            $errorMessage = $result['header']['resultMessage'];
        } elseif (isset($result['message'])) {
            $errorMessage = $result['message'];
        }
        
        $this->error("  错误信息: {$errorMessage}");
        
        if (isset($result['header']['resultCode'])) {
            $this->line("  错误码: {$result['header']['resultCode']}");
        }
    }

    /**
     * 判断是否成功
     */
    protected function isSuccess(array $result): bool
    {
        if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
            return true;
        }
        
        if (isset($result['success']) && $result['success'] === true) {
            return true;
        }
        
        return false;
    }

    /**
     * 诊断错误
     */
    protected function diagnoseError(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $errorMessage = '';
        if (isset($result['header']['resultMessage'])) {
            $errorMessage = $result['header']['resultMessage'];
        } elseif (isset($result['message'])) {
            $errorMessage = $result['message'];
        }
        
        if (strpos($errorMessage, 'serviceName') !== false || strpos($errorMessage, 'ServiceName') !== false) {
            $this->warn("  问题: serviceName 错误");
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. serviceName 应该是 'CancelOrderConfirm'");
            $this->line("    2. 检查代码中是否正确设置了 serviceName");
            $this->line("    3. 可能是代码缓存问题，请执行: php artisan optimize:clear");
        } elseif (strpos($errorMessage, '请求数据异常') !== false || strpos($errorMessage, 'Abnormal request data') !== false) {
            $this->warn("  问题: 请求数据异常");
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. URL 不正确，应该是 order/notice.do 接口");
            $this->line("    2. body 数据结构不正确");
            $this->line("    3. 必填字段缺失或格式错误");
            $this->line("    4. 签名错误");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 使用 --show-body 查看完整的请求数据");
            $this->line("    2. 检查日志中的 '携程订单取消确认请求数据' 记录");
            $this->line("    3. 确认 body_data 中的字段是否完整");
        } elseif (strpos($errorMessage, '签名') !== false || strpos($errorMessage, 'sign') !== false) {
            $this->warn("  问题: 签名错误");
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. secret_key 配置错误");
            $this->line("    2. 签名算法不正确");
            $this->line("    3. 请求时间格式错误");
        } else {
            $this->line("  错误信息: {$errorMessage}");
            $this->line("  请查看详细日志获取更多信息");
        }
    }

    /**
     * 从日志中提取并显示body数据
     */
    protected function displayBodyDataFromLogs(): void
    {
        $this->newLine();
        $this->info("[请求Body数据]");
        $this->warn("  提示: 请查看日志文件中的 '携程订单取消确认请求数据' 记录");
        $this->warn("  该记录包含完整的 body_data（加密前的数据）");
        $this->line("  日志文件: storage/logs/laravel.log");
        $this->line("  搜索关键词: '携程订单取消确认请求数据'");
    }

    /**
     * 保存请求数据到文件
     */
    protected function saveRequestData(string $filename, array $requestData, array $responseData): void
    {
        try {
            $data = [
                'timestamp' => date('Y-m-d H:i:s'),
                'request' => $requestData,
                'response' => $responseData,
            ];
            
            $fullPath = base_path($filename);
            file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("  ✓ 请求数据已保存到: {$fullPath}");
        } catch (\Exception $e) {
            $this->warn("  ✗ 保存请求数据失败: " . $e->getMessage());
        }
    }

    /**
     * 获取 CtripService 的 client（用于手动模式）
     */
    protected function getClient()
    {
        // 使用反射获取 client
        $reflection = new \ReflectionClass($this->ctripService);
        $method = $reflection->getMethod('getClient');
        $method->setAccessible(true);
        return $method->invoke($this->ctripService);
    }
}

