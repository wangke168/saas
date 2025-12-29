<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Resource\HengdianService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestHengdianBook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:hengdian-book 
                            {--order= : 使用订单ID测试}
                            {--ota-order-id= : OTA订单号（手动模式）}
                            {--package-id= : 产品编码（手动模式）}
                            {--hotel-id= : 酒店ID或编码（手动模式）}
                            {--room-type= : 房型ID或编码（手动模式）}
                            {--check-in= : 入住日期，格式：YYYY-MM-DD（手动模式）}
                            {--check-out= : 离店日期，格式：YYYY-MM-DD（手动模式）}
                            {--amount= : 订单金额（元）（手动模式）}
                            {--room-num= : 房间数量（手动模式）}
                            {--contact-name= : 联系人姓名（手动模式）}
                            {--contact-tel= : 联系人电话（手动模式）}
                            {--guest-name= : 客人姓名（手动模式）}
                            {--guest-id-code= : 客人身份证号（手动模式）}
                            {--json : 以JSON格式输出}
                            {--save= : 保存请求XML到文件}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试横店接口 - BookRQ（下单预订）';

    public function __construct(
        protected HengdianService $hengdianService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('横店接口测试 - BookRQ（下单预订）');
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

        $order = Order::with(['hotel.scenicSpot', 'product', 'roomType', 'otaPlatform'])->find($orderId);
        
        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return 1;
        }

        $this->info("[订单信息]");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  OTA订单号: {$order->ota_order_no}");
        $this->line("  产品: {$order->product->name} (ID: {$order->product_id})");
        $this->line("  酒店: {$order->hotel->name} (ID: {$order->hotel_id})");
        $this->line("  房型: {$order->roomType->name} (ID: {$order->room_type_id})");
        $this->line("  入住日期: {$order->check_in_date->format('Y-m-d')}");
        $this->line("  离店日期: {$order->check_out_date->format('Y-m-d')}");
        $this->line("  房间数: {$order->room_count}");
        $this->line("  订单金额: {$order->total_amount} 元");
        $this->line("  联系人: {$order->contact_name} ({$order->contact_phone})");
        $this->line("  客人信息: " . json_encode($order->guest_info, JSON_UNESCAPED_UNICODE));
        $this->newLine();

        // 调用横店服务
        $this->info("[开始测试]");
        $this->line("调用 HengdianService::book()...");
        $this->newLine();

        try {
            $result = $this->hengdianService->book($order);
            
            $this->displayResult($result, $order);
            
            return $result['success'] ? 0 : 1;
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
        $data = [];
        
        $data['OtaOrderId'] = $this->option('ota-order-id') ?: $this->ask('OTA订单号', 'TEST-ORDER-' . time());
        $data['PackageId'] = $this->option('package-id') ?: $this->ask('产品编码（PLU格式，如：001|123|3326）');
        $data['HotelId'] = $this->option('hotel-id') ?: $this->ask('酒店ID或编码');
        $data['RoomType'] = $this->option('room-type') ?: $this->ask('房型ID或编码');
        $data['CheckIn'] = $this->option('check-in') ?: $this->ask('入住日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+7 days')));
        $data['CheckOut'] = $this->option('check-out') ?: $this->ask('离店日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+8 days')));
        $data['Amount'] = (int)(($this->option('amount') ?: $this->ask('订单金额（元）', '1098')) * 100); // 转换为分
        $data['RoomNum'] = (int)($this->option('room-num') ?: $this->ask('房间数量', '1'));
        $data['PaymentType'] = 1; // 预付
        $data['ContactName'] = $this->option('contact-name') ?: $this->ask('联系人姓名', '测试用户');
        $data['ContactTel'] = $this->option('contact-tel') ?: $this->ask('联系人电话', '19941445464');
        
        // 客人信息
        $guestName = $this->option('guest-name') ?: $this->ask('客人姓名', '王书桓');
        $guestIdCode = $this->option('guest-id-code') ?: $this->ask('客人身份证号', '530627200211154118');
        
        $data['OrderGuests'] = [
            'OrderGuest' => [
                [
                    'Name' => $guestName,
                    'IdCode' => $guestIdCode,
                ],
            ],
        ];
        
        $data['Comment'] = '';
        $data['Extensions'] = json_encode([]);

        $this->newLine();
        $this->info("[测试数据]");
        $this->displayData($data);
        $this->newLine();

        // 获取客户端（需要创建一个临时订单对象）
        $this->info("[获取配置]");
        try {
            // 尝试从数据库获取一个订单来获取配置
            $sampleOrder = Order::with(['hotel.scenicSpot', 'otaPlatform'])->first();
            
            $client = $this->hengdianService->getClient($sampleOrder);
            
            $this->line("  ✓ 配置获取成功");
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("  ✗ 配置获取失败: " . $e->getMessage());
            $this->error("  请确保:");
            $this->error("    1. 数据库中有订单记录");
            $this->error("    2. 订单关联的景区已配置资源方");
            $this->error("    3. 或者 .env 文件中配置了 HENGDIAN_* 参数");
            return 1;
        }

        // 调用横店客户端
        $this->info("[开始测试]");
        $this->line("调用 HengdianClient::book()...");
        $this->newLine();

        try {
            $result = $client->book($data);
            
            $this->displayResult($result, null, $data);
            
            // 保存XML到文件（如果指定）
            if ($saveFile = $this->option('save')) {
                $this->saveRequestXml($saveFile, $data, $client);
            }
            
            return $result['success'] ? 0 : 1;
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
     * 显示测试数据
     */
    protected function displayData(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'OrderGuests') {
                $this->line("  {$key}:");
                if (isset($value['OrderGuest']) && is_array($value['OrderGuest'])) {
                    foreach ($value['OrderGuest'] as $index => $guest) {
                        $this->line("    [客人 " . ($index + 1) . "]");
                        $this->line("      姓名: " . ($guest['Name'] ?? ''));
                        $this->line("      身份证: " . ($guest['IdCode'] ?? ''));
                    }
                }
            } elseif ($key === 'Amount') {
                $this->line("  {$key}: " . ($value / 100) . " 元 (" . $value . " 分)");
            } else {
                $this->line("  {$key}: " . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value));
            }
        }
    }

    /**
     * 显示测试结果
     */
    protected function displayResult(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $this->info("[测试结果]");
        $this->newLine();

        if ($result['success'] ?? false) {
            $this->info("  ✓ 成功");
            $this->newLine();
            
            if (isset($result['data'])) {
                $this->line("  响应数据:");
                if ($this->option('json')) {
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    if (is_object($result['data']) && isset($result['data']->OrderId)) {
                        $this->line("    订单ID: " . (string)$result['data']->OrderId);
                    }
                    if (is_object($result['data']) && isset($result['data']->ResultCode)) {
                        $this->line("    结果码: " . (string)$result['data']->ResultCode);
                    }
                    if (is_object($result['data']) && isset($result['data']->Message)) {
                        $this->line("    消息: " . (string)$result['data']->Message);
                    }
                }
            }
        } else {
            $this->error("  ✗ 失败");
            $this->newLine();
            
            $errorMessage = $result['message'] ?? '未知错误';
            $this->error("  错误信息: {$errorMessage}");
            
            if (isset($result['data'])) {
                if (is_object($result['data'])) {
                    $resultCode = (string)($result['data']->ResultCode ?? '');
                    $message = (string)($result['data']->Message ?? '');
                    
                    if ($resultCode) {
                        $this->line("  错误码: {$resultCode}");
                    }
                    if ($message && $message !== $errorMessage) {
                        $this->line("  详细消息: {$message}");
                    }
                }
            }
            
            $this->newLine();
            $this->info("[诊断信息]");
            $this->diagnoseError($result, $order, $manualData);
        }
        
        $this->newLine();
        $this->line("详细日志请查看: storage/logs/laravel.log");
    }

    /**
     * 诊断错误
     */
    protected function diagnoseError(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $errorMessage = $result['message'] ?? '';
        
        if (strpos($errorMessage, '入住人不能为空') !== false) {
            $this->warn("  问题: 横店返回'入住人不能为空'");
            $this->newLine();
            
            if ($order) {
                $guestInfo = $order->guest_info;
                $this->line("  订单客人信息:");
                $this->line("    " . json_encode($guestInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->newLine();
                
                if (empty($guestInfo) || !is_array($guestInfo)) {
                    $this->error("    ✗ 客人信息为空或格式错误");
                } else {
                    $this->line("    ✓ 客人信息存在");
                    foreach ($guestInfo as $index => $guest) {
                        $name = $guest['name'] ?? $guest['Name'] ?? '';
                        $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            } elseif ($manualData) {
                $orderGuests = $manualData['OrderGuests']['OrderGuest'] ?? [];
                $this->line("  测试数据客人信息:");
                if (empty($orderGuests)) {
                    $this->error("    ✗ 客人信息为空");
                } else {
                    foreach ($orderGuests as $index => $guest) {
                        $name = $guest['Name'] ?? '';
                        $idCode = $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            }
            
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. XML中OrderGuests节点格式不正确");
            $this->line("    2. OrderGuest节点的Name或IdCode字段为空或格式错误");
            $this->line("    3. 横店系统验证逻辑变更");
            $this->line("    4. 认证信息不正确，导致请求被拒绝");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查日志中的XML请求内容");
            $this->line("    2. 确认客人信息的字段名是否正确（Name/IdCode）");
            $this->line("    3. 确认认证信息是否正确（用户名/密码）");
            $this->line("    4. 联系横店技术支持确认接口要求");
        } elseif (strpos($errorMessage, '配置不存在') !== false) {
            $this->warn("  问题: 资源配置不存在");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查数据库中是否有 resource_configs 记录");
            $this->line("    2. 检查 .env 文件中是否有 HENGDIAN_* 配置");
            $this->line("    3. 确认景区是否配置了资源方");
        } else {
            $this->line("  错误信息: {$errorMessage}");
            $this->line("  请查看详细日志获取更多信息");
        }
    }

    /**
     * 保存请求XML到文件
     */
    protected function saveRequestXml(string $filename, array $data, $client): void
    {
        try {
            // 使用反射调用 buildXml 方法
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('buildXml');
            $method->setAccessible(true);
            
            $xml = $method->invoke($client, 'BookRQ', $data);
            
            $fullPath = base_path($filename);
            file_put_contents($fullPath, $xml);
            $this->info("  ✓ 请求XML已保存到: {$fullPath}");
        } catch (\Exception $e) {
            $this->warn("  ✗ 保存XML失败: " . $e->getMessage());
            $this->warn("  提示: 请检查日志中的XML请求内容");
        }
    }
}


declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Resource\HengdianService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestHengdianBook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:hengdian-book 
                            {--order= : 使用订单ID测试}
                            {--ota-order-id= : OTA订单号（手动模式）}
                            {--package-id= : 产品编码（手动模式）}
                            {--hotel-id= : 酒店ID或编码（手动模式）}
                            {--room-type= : 房型ID或编码（手动模式）}
                            {--check-in= : 入住日期，格式：YYYY-MM-DD（手动模式）}
                            {--check-out= : 离店日期，格式：YYYY-MM-DD（手动模式）}
                            {--amount= : 订单金额（元）（手动模式）}
                            {--room-num= : 房间数量（手动模式）}
                            {--contact-name= : 联系人姓名（手动模式）}
                            {--contact-tel= : 联系人电话（手动模式）}
                            {--guest-name= : 客人姓名（手动模式）}
                            {--guest-id-code= : 客人身份证号（手动模式）}
                            {--json : 以JSON格式输出}
                            {--save= : 保存请求XML到文件}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试横店接口 - BookRQ（下单预订）';

    public function __construct(
        protected HengdianService $hengdianService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('横店接口测试 - BookRQ（下单预订）');
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

        $order = Order::with(['hotel.scenicSpot', 'product', 'roomType', 'otaPlatform'])->find($orderId);
        
        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return 1;
        }

        $this->info("[订单信息]");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  OTA订单号: {$order->ota_order_no}");
        $this->line("  产品: {$order->product->name} (ID: {$order->product_id})");
        $this->line("  酒店: {$order->hotel->name} (ID: {$order->hotel_id})");
        $this->line("  房型: {$order->roomType->name} (ID: {$order->room_type_id})");
        $this->line("  入住日期: {$order->check_in_date->format('Y-m-d')}");
        $this->line("  离店日期: {$order->check_out_date->format('Y-m-d')}");
        $this->line("  房间数: {$order->room_count}");
        $this->line("  订单金额: {$order->total_amount} 元");
        $this->line("  联系人: {$order->contact_name} ({$order->contact_phone})");
        $this->line("  客人信息: " . json_encode($order->guest_info, JSON_UNESCAPED_UNICODE));
        $this->newLine();

        // 调用横店服务
        $this->info("[开始测试]");
        $this->line("调用 HengdianService::book()...");
        $this->newLine();

        try {
            $result = $this->hengdianService->book($order);
            
            $this->displayResult($result, $order);
            
            return $result['success'] ? 0 : 1;
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
        $data = [];
        
        $data['OtaOrderId'] = $this->option('ota-order-id') ?: $this->ask('OTA订单号', 'TEST-ORDER-' . time());
        $data['PackageId'] = $this->option('package-id') ?: $this->ask('产品编码（PLU格式，如：001|123|3326）');
        $data['HotelId'] = $this->option('hotel-id') ?: $this->ask('酒店ID或编码');
        $data['RoomType'] = $this->option('room-type') ?: $this->ask('房型ID或编码');
        $data['CheckIn'] = $this->option('check-in') ?: $this->ask('入住日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+7 days')));
        $data['CheckOut'] = $this->option('check-out') ?: $this->ask('离店日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+8 days')));
        $data['Amount'] = (int)(($this->option('amount') ?: $this->ask('订单金额（元）', '1098')) * 100); // 转换为分
        $data['RoomNum'] = (int)($this->option('room-num') ?: $this->ask('房间数量', '1'));
        $data['PaymentType'] = 1; // 预付
        $data['ContactName'] = $this->option('contact-name') ?: $this->ask('联系人姓名', '测试用户');
        $data['ContactTel'] = $this->option('contact-tel') ?: $this->ask('联系人电话', '19941445464');
        
        // 客人信息
        $guestName = $this->option('guest-name') ?: $this->ask('客人姓名', '王书桓');
        $guestIdCode = $this->option('guest-id-code') ?: $this->ask('客人身份证号', '530627200211154118');
        
        $data['OrderGuests'] = [
            'OrderGuest' => [
                [
                    'Name' => $guestName,
                    'IdCode' => $guestIdCode,
                ],
            ],
        ];
        
        $data['Comment'] = '';
        $data['Extensions'] = json_encode([]);

        $this->newLine();
        $this->info("[测试数据]");
        $this->displayData($data);
        $this->newLine();

        // 获取客户端（需要创建一个临时订单对象）
        $this->info("[获取配置]");
        try {
            // 尝试从数据库获取一个订单来获取配置
            $sampleOrder = Order::with(['hotel.scenicSpot', 'otaPlatform'])->first();
            
            $client = $this->hengdianService->getClient($sampleOrder);
            
            $this->line("  ✓ 配置获取成功");
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("  ✗ 配置获取失败: " . $e->getMessage());
            $this->error("  请确保:");
            $this->error("    1. 数据库中有订单记录");
            $this->error("    2. 订单关联的景区已配置资源方");
            $this->error("    3. 或者 .env 文件中配置了 HENGDIAN_* 参数");
            return 1;
        }

        // 调用横店客户端
        $this->info("[开始测试]");
        $this->line("调用 HengdianClient::book()...");
        $this->newLine();

        try {
            $result = $client->book($data);
            
            $this->displayResult($result, null, $data);
            
            // 保存XML到文件（如果指定）
            if ($saveFile = $this->option('save')) {
                $this->saveRequestXml($saveFile, $data, $client);
            }
            
            return $result['success'] ? 0 : 1;
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
     * 显示测试数据
     */
    protected function displayData(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'OrderGuests') {
                $this->line("  {$key}:");
                if (isset($value['OrderGuest']) && is_array($value['OrderGuest'])) {
                    foreach ($value['OrderGuest'] as $index => $guest) {
                        $this->line("    [客人 " . ($index + 1) . "]");
                        $this->line("      姓名: " . ($guest['Name'] ?? ''));
                        $this->line("      身份证: " . ($guest['IdCode'] ?? ''));
                    }
                }
            } elseif ($key === 'Amount') {
                $this->line("  {$key}: " . ($value / 100) . " 元 (" . $value . " 分)");
            } else {
                $this->line("  {$key}: " . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value));
            }
        }
    }

    /**
     * 显示测试结果
     */
    protected function displayResult(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $this->info("[测试结果]");
        $this->newLine();

        if ($result['success'] ?? false) {
            $this->info("  ✓ 成功");
            $this->newLine();
            
            if (isset($result['data'])) {
                $this->line("  响应数据:");
                if ($this->option('json')) {
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    if (is_object($result['data']) && isset($result['data']->OrderId)) {
                        $this->line("    订单ID: " . (string)$result['data']->OrderId);
                    }
                    if (is_object($result['data']) && isset($result['data']->ResultCode)) {
                        $this->line("    结果码: " . (string)$result['data']->ResultCode);
                    }
                    if (is_object($result['data']) && isset($result['data']->Message)) {
                        $this->line("    消息: " . (string)$result['data']->Message);
                    }
                }
            }
        } else {
            $this->error("  ✗ 失败");
            $this->newLine();
            
            $errorMessage = $result['message'] ?? '未知错误';
            $this->error("  错误信息: {$errorMessage}");
            
            if (isset($result['data'])) {
                if (is_object($result['data'])) {
                    $resultCode = (string)($result['data']->ResultCode ?? '');
                    $message = (string)($result['data']->Message ?? '');
                    
                    if ($resultCode) {
                        $this->line("  错误码: {$resultCode}");
                    }
                    if ($message && $message !== $errorMessage) {
                        $this->line("  详细消息: {$message}");
                    }
                }
            }
            
            $this->newLine();
            $this->info("[诊断信息]");
            $this->diagnoseError($result, $order, $manualData);
        }
        
        $this->newLine();
        $this->line("详细日志请查看: storage/logs/laravel.log");
    }

    /**
     * 诊断错误
     */
    protected function diagnoseError(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $errorMessage = $result['message'] ?? '';
        
        if (strpos($errorMessage, '入住人不能为空') !== false) {
            $this->warn("  问题: 横店返回'入住人不能为空'");
            $this->newLine();
            
            if ($order) {
                $guestInfo = $order->guest_info;
                $this->line("  订单客人信息:");
                $this->line("    " . json_encode($guestInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->newLine();
                
                if (empty($guestInfo) || !is_array($guestInfo)) {
                    $this->error("    ✗ 客人信息为空或格式错误");
                } else {
                    $this->line("    ✓ 客人信息存在");
                    foreach ($guestInfo as $index => $guest) {
                        $name = $guest['name'] ?? $guest['Name'] ?? '';
                        $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            } elseif ($manualData) {
                $orderGuests = $manualData['OrderGuests']['OrderGuest'] ?? [];
                $this->line("  测试数据客人信息:");
                if (empty($orderGuests)) {
                    $this->error("    ✗ 客人信息为空");
                } else {
                    foreach ($orderGuests as $index => $guest) {
                        $name = $guest['Name'] ?? '';
                        $idCode = $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            }
            
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. XML中OrderGuests节点格式不正确");
            $this->line("    2. OrderGuest节点的Name或IdCode字段为空或格式错误");
            $this->line("    3. 横店系统验证逻辑变更");
            $this->line("    4. 认证信息不正确，导致请求被拒绝");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查日志中的XML请求内容");
            $this->line("    2. 确认客人信息的字段名是否正确（Name/IdCode）");
            $this->line("    3. 确认认证信息是否正确（用户名/密码）");
            $this->line("    4. 联系横店技术支持确认接口要求");
        } elseif (strpos($errorMessage, '配置不存在') !== false) {
            $this->warn("  问题: 资源配置不存在");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查数据库中是否有 resource_configs 记录");
            $this->line("    2. 检查 .env 文件中是否有 HENGDIAN_* 配置");
            $this->line("    3. 确认景区是否配置了资源方");
        } else {
            $this->line("  错误信息: {$errorMessage}");
            $this->line("  请查看详细日志获取更多信息");
        }
    }

    /**
     * 保存请求XML到文件
     */
    protected function saveRequestXml(string $filename, array $data, $client): void
    {
        try {
            // 使用反射调用 buildXml 方法
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('buildXml');
            $method->setAccessible(true);
            
            $xml = $method->invoke($client, 'BookRQ', $data);
            
            $fullPath = base_path($filename);
            file_put_contents($fullPath, $xml);
            $this->info("  ✓ 请求XML已保存到: {$fullPath}");
        } catch (\Exception $e) {
            $this->warn("  ✗ 保存XML失败: " . $e->getMessage());
            $this->warn("  提示: 请检查日志中的XML请求内容");
        }
    }
}


declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Resource\HengdianService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestHengdianBook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:hengdian-book 
                            {--order= : 使用订单ID测试}
                            {--ota-order-id= : OTA订单号（手动模式）}
                            {--package-id= : 产品编码（手动模式）}
                            {--hotel-id= : 酒店ID或编码（手动模式）}
                            {--room-type= : 房型ID或编码（手动模式）}
                            {--check-in= : 入住日期，格式：YYYY-MM-DD（手动模式）}
                            {--check-out= : 离店日期，格式：YYYY-MM-DD（手动模式）}
                            {--amount= : 订单金额（元）（手动模式）}
                            {--room-num= : 房间数量（手动模式）}
                            {--contact-name= : 联系人姓名（手动模式）}
                            {--contact-tel= : 联系人电话（手动模式）}
                            {--guest-name= : 客人姓名（手动模式）}
                            {--guest-id-code= : 客人身份证号（手动模式）}
                            {--json : 以JSON格式输出}
                            {--save= : 保存请求XML到文件}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试横店接口 - BookRQ（下单预订）';

    public function __construct(
        protected HengdianService $hengdianService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('横店接口测试 - BookRQ（下单预订）');
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

        $order = Order::with(['hotel.scenicSpot', 'product', 'roomType', 'otaPlatform'])->find($orderId);
        
        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return 1;
        }

        $this->info("[订单信息]");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  OTA订单号: {$order->ota_order_no}");
        $this->line("  产品: {$order->product->name} (ID: {$order->product_id})");
        $this->line("  酒店: {$order->hotel->name} (ID: {$order->hotel_id})");
        $this->line("  房型: {$order->roomType->name} (ID: {$order->room_type_id})");
        $this->line("  入住日期: {$order->check_in_date->format('Y-m-d')}");
        $this->line("  离店日期: {$order->check_out_date->format('Y-m-d')}");
        $this->line("  房间数: {$order->room_count}");
        $this->line("  订单金额: {$order->total_amount} 元");
        $this->line("  联系人: {$order->contact_name} ({$order->contact_phone})");
        $this->line("  客人信息: " . json_encode($order->guest_info, JSON_UNESCAPED_UNICODE));
        $this->newLine();

        // 调用横店服务
        $this->info("[开始测试]");
        $this->line("调用 HengdianService::book()...");
        $this->newLine();

        try {
            $result = $this->hengdianService->book($order);
            
            $this->displayResult($result, $order);
            
            return $result['success'] ? 0 : 1;
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
        $data = [];
        
        $data['OtaOrderId'] = $this->option('ota-order-id') ?: $this->ask('OTA订单号', 'TEST-ORDER-' . time());
        $data['PackageId'] = $this->option('package-id') ?: $this->ask('产品编码（PLU格式，如：001|123|3326）');
        $data['HotelId'] = $this->option('hotel-id') ?: $this->ask('酒店ID或编码');
        $data['RoomType'] = $this->option('room-type') ?: $this->ask('房型ID或编码');
        $data['CheckIn'] = $this->option('check-in') ?: $this->ask('入住日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+7 days')));
        $data['CheckOut'] = $this->option('check-out') ?: $this->ask('离店日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+8 days')));
        $data['Amount'] = (int)(($this->option('amount') ?: $this->ask('订单金额（元）', '1098')) * 100); // 转换为分
        $data['RoomNum'] = (int)($this->option('room-num') ?: $this->ask('房间数量', '1'));
        $data['PaymentType'] = 1; // 预付
        $data['ContactName'] = $this->option('contact-name') ?: $this->ask('联系人姓名', '测试用户');
        $data['ContactTel'] = $this->option('contact-tel') ?: $this->ask('联系人电话', '19941445464');
        
        // 客人信息
        $guestName = $this->option('guest-name') ?: $this->ask('客人姓名', '王书桓');
        $guestIdCode = $this->option('guest-id-code') ?: $this->ask('客人身份证号', '530627200211154118');
        
        $data['OrderGuests'] = [
            'OrderGuest' => [
                [
                    'Name' => $guestName,
                    'IdCode' => $guestIdCode,
                ],
            ],
        ];
        
        $data['Comment'] = '';
        $data['Extensions'] = json_encode([]);

        $this->newLine();
        $this->info("[测试数据]");
        $this->displayData($data);
        $this->newLine();

        // 获取客户端（需要创建一个临时订单对象）
        $this->info("[获取配置]");
        try {
            // 尝试从数据库获取一个订单来获取配置
            $sampleOrder = Order::with(['hotel.scenicSpot', 'otaPlatform'])->first();
            
            $client = $this->hengdianService->getClient($sampleOrder);
            
            $this->line("  ✓ 配置获取成功");
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("  ✗ 配置获取失败: " . $e->getMessage());
            $this->error("  请确保:");
            $this->error("    1. 数据库中有订单记录");
            $this->error("    2. 订单关联的景区已配置资源方");
            $this->error("    3. 或者 .env 文件中配置了 HENGDIAN_* 参数");
            return 1;
        }

        // 调用横店客户端
        $this->info("[开始测试]");
        $this->line("调用 HengdianClient::book()...");
        $this->newLine();

        try {
            $result = $client->book($data);
            
            $this->displayResult($result, null, $data);
            
            // 保存XML到文件（如果指定）
            if ($saveFile = $this->option('save')) {
                $this->saveRequestXml($saveFile, $data, $client);
            }
            
            return $result['success'] ? 0 : 1;
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
     * 显示测试数据
     */
    protected function displayData(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'OrderGuests') {
                $this->line("  {$key}:");
                if (isset($value['OrderGuest']) && is_array($value['OrderGuest'])) {
                    foreach ($value['OrderGuest'] as $index => $guest) {
                        $this->line("    [客人 " . ($index + 1) . "]");
                        $this->line("      姓名: " . ($guest['Name'] ?? ''));
                        $this->line("      身份证: " . ($guest['IdCode'] ?? ''));
                    }
                }
            } elseif ($key === 'Amount') {
                $this->line("  {$key}: " . ($value / 100) . " 元 (" . $value . " 分)");
            } else {
                $this->line("  {$key}: " . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value));
            }
        }
    }

    /**
     * 显示测试结果
     */
    protected function displayResult(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $this->info("[测试结果]");
        $this->newLine();

        if ($result['success'] ?? false) {
            $this->info("  ✓ 成功");
            $this->newLine();
            
            if (isset($result['data'])) {
                $this->line("  响应数据:");
                if ($this->option('json')) {
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    if (is_object($result['data']) && isset($result['data']->OrderId)) {
                        $this->line("    订单ID: " . (string)$result['data']->OrderId);
                    }
                    if (is_object($result['data']) && isset($result['data']->ResultCode)) {
                        $this->line("    结果码: " . (string)$result['data']->ResultCode);
                    }
                    if (is_object($result['data']) && isset($result['data']->Message)) {
                        $this->line("    消息: " . (string)$result['data']->Message);
                    }
                }
            }
        } else {
            $this->error("  ✗ 失败");
            $this->newLine();
            
            $errorMessage = $result['message'] ?? '未知错误';
            $this->error("  错误信息: {$errorMessage}");
            
            if (isset($result['data'])) {
                if (is_object($result['data'])) {
                    $resultCode = (string)($result['data']->ResultCode ?? '');
                    $message = (string)($result['data']->Message ?? '');
                    
                    if ($resultCode) {
                        $this->line("  错误码: {$resultCode}");
                    }
                    if ($message && $message !== $errorMessage) {
                        $this->line("  详细消息: {$message}");
                    }
                }
            }
            
            $this->newLine();
            $this->info("[诊断信息]");
            $this->diagnoseError($result, $order, $manualData);
        }
        
        $this->newLine();
        $this->line("详细日志请查看: storage/logs/laravel.log");
    }

    /**
     * 诊断错误
     */
    protected function diagnoseError(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $errorMessage = $result['message'] ?? '';
        
        if (strpos($errorMessage, '入住人不能为空') !== false) {
            $this->warn("  问题: 横店返回'入住人不能为空'");
            $this->newLine();
            
            if ($order) {
                $guestInfo = $order->guest_info;
                $this->line("  订单客人信息:");
                $this->line("    " . json_encode($guestInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->newLine();
                
                if (empty($guestInfo) || !is_array($guestInfo)) {
                    $this->error("    ✗ 客人信息为空或格式错误");
                } else {
                    $this->line("    ✓ 客人信息存在");
                    foreach ($guestInfo as $index => $guest) {
                        $name = $guest['name'] ?? $guest['Name'] ?? '';
                        $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            } elseif ($manualData) {
                $orderGuests = $manualData['OrderGuests']['OrderGuest'] ?? [];
                $this->line("  测试数据客人信息:");
                if (empty($orderGuests)) {
                    $this->error("    ✗ 客人信息为空");
                } else {
                    foreach ($orderGuests as $index => $guest) {
                        $name = $guest['Name'] ?? '';
                        $idCode = $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            }
            
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. XML中OrderGuests节点格式不正确");
            $this->line("    2. OrderGuest节点的Name或IdCode字段为空或格式错误");
            $this->line("    3. 横店系统验证逻辑变更");
            $this->line("    4. 认证信息不正确，导致请求被拒绝");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查日志中的XML请求内容");
            $this->line("    2. 确认客人信息的字段名是否正确（Name/IdCode）");
            $this->line("    3. 确认认证信息是否正确（用户名/密码）");
            $this->line("    4. 联系横店技术支持确认接口要求");
        } elseif (strpos($errorMessage, '配置不存在') !== false) {
            $this->warn("  问题: 资源配置不存在");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查数据库中是否有 resource_configs 记录");
            $this->line("    2. 检查 .env 文件中是否有 HENGDIAN_* 配置");
            $this->line("    3. 确认景区是否配置了资源方");
        } else {
            $this->line("  错误信息: {$errorMessage}");
            $this->line("  请查看详细日志获取更多信息");
        }
    }

    /**
     * 保存请求XML到文件
     */
    protected function saveRequestXml(string $filename, array $data, $client): void
    {
        try {
            // 使用反射调用 buildXml 方法
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('buildXml');
            $method->setAccessible(true);
            
            $xml = $method->invoke($client, 'BookRQ', $data);
            
            $fullPath = base_path($filename);
            file_put_contents($fullPath, $xml);
            $this->info("  ✓ 请求XML已保存到: {$fullPath}");
        } catch (\Exception $e) {
            $this->warn("  ✗ 保存XML失败: " . $e->getMessage());
            $this->warn("  提示: 请检查日志中的XML请求内容");
        }
    }
}


declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Resource\HengdianService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestHengdianBook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:hengdian-book 
                            {--order= : 使用订单ID测试}
                            {--ota-order-id= : OTA订单号（手动模式）}
                            {--package-id= : 产品编码（手动模式）}
                            {--hotel-id= : 酒店ID或编码（手动模式）}
                            {--room-type= : 房型ID或编码（手动模式）}
                            {--check-in= : 入住日期，格式：YYYY-MM-DD（手动模式）}
                            {--check-out= : 离店日期，格式：YYYY-MM-DD（手动模式）}
                            {--amount= : 订单金额（元）（手动模式）}
                            {--room-num= : 房间数量（手动模式）}
                            {--contact-name= : 联系人姓名（手动模式）}
                            {--contact-tel= : 联系人电话（手动模式）}
                            {--guest-name= : 客人姓名（手动模式）}
                            {--guest-id-code= : 客人身份证号（手动模式）}
                            {--json : 以JSON格式输出}
                            {--save= : 保存请求XML到文件}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试横店接口 - BookRQ（下单预订）';

    public function __construct(
        protected HengdianService $hengdianService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('横店接口测试 - BookRQ（下单预订）');
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

        $order = Order::with(['hotel.scenicSpot', 'product', 'roomType', 'otaPlatform'])->find($orderId);
        
        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return 1;
        }

        $this->info("[订单信息]");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  OTA订单号: {$order->ota_order_no}");
        $this->line("  产品: {$order->product->name} (ID: {$order->product_id})");
        $this->line("  酒店: {$order->hotel->name} (ID: {$order->hotel_id})");
        $this->line("  房型: {$order->roomType->name} (ID: {$order->room_type_id})");
        $this->line("  入住日期: {$order->check_in_date->format('Y-m-d')}");
        $this->line("  离店日期: {$order->check_out_date->format('Y-m-d')}");
        $this->line("  房间数: {$order->room_count}");
        $this->line("  订单金额: {$order->total_amount} 元");
        $this->line("  联系人: {$order->contact_name} ({$order->contact_phone})");
        $this->line("  客人信息: " . json_encode($order->guest_info, JSON_UNESCAPED_UNICODE));
        $this->newLine();

        // 调用横店服务
        $this->info("[开始测试]");
        $this->line("调用 HengdianService::book()...");
        $this->newLine();

        try {
            $result = $this->hengdianService->book($order);
            
            $this->displayResult($result, $order);
            
            return $result['success'] ? 0 : 1;
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
        $data = [];
        
        $data['OtaOrderId'] = $this->option('ota-order-id') ?: $this->ask('OTA订单号', 'TEST-ORDER-' . time());
        $data['PackageId'] = $this->option('package-id') ?: $this->ask('产品编码（PLU格式，如：001|123|3326）');
        $data['HotelId'] = $this->option('hotel-id') ?: $this->ask('酒店ID或编码');
        $data['RoomType'] = $this->option('room-type') ?: $this->ask('房型ID或编码');
        $data['CheckIn'] = $this->option('check-in') ?: $this->ask('入住日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+7 days')));
        $data['CheckOut'] = $this->option('check-out') ?: $this->ask('离店日期（YYYY-MM-DD）', date('Y-m-d', strtotime('+8 days')));
        $data['Amount'] = (int)(($this->option('amount') ?: $this->ask('订单金额（元）', '1098')) * 100); // 转换为分
        $data['RoomNum'] = (int)($this->option('room-num') ?: $this->ask('房间数量', '1'));
        $data['PaymentType'] = 1; // 预付
        $data['ContactName'] = $this->option('contact-name') ?: $this->ask('联系人姓名', '测试用户');
        $data['ContactTel'] = $this->option('contact-tel') ?: $this->ask('联系人电话', '19941445464');
        
        // 客人信息
        $guestName = $this->option('guest-name') ?: $this->ask('客人姓名', '王书桓');
        $guestIdCode = $this->option('guest-id-code') ?: $this->ask('客人身份证号', '530627200211154118');
        
        $data['OrderGuests'] = [
            'OrderGuest' => [
                [
                    'Name' => $guestName,
                    'IdCode' => $guestIdCode,
                ],
            ],
        ];
        
        $data['Comment'] = '';
        $data['Extensions'] = json_encode([]);

        $this->newLine();
        $this->info("[测试数据]");
        $this->displayData($data);
        $this->newLine();

        // 获取客户端（需要创建一个临时订单对象）
        $this->info("[获取配置]");
        try {
            // 尝试从数据库获取一个订单来获取配置
            $sampleOrder = Order::with(['hotel.scenicSpot', 'otaPlatform'])->first();
            
            $client = $this->hengdianService->getClient($sampleOrder);
            
            $this->line("  ✓ 配置获取成功");
            $this->newLine();
        } catch (\Exception $e) {
            $this->error("  ✗ 配置获取失败: " . $e->getMessage());
            $this->error("  请确保:");
            $this->error("    1. 数据库中有订单记录");
            $this->error("    2. 订单关联的景区已配置资源方");
            $this->error("    3. 或者 .env 文件中配置了 HENGDIAN_* 参数");
            return 1;
        }

        // 调用横店客户端
        $this->info("[开始测试]");
        $this->line("调用 HengdianClient::book()...");
        $this->newLine();

        try {
            $result = $client->book($data);
            
            $this->displayResult($result, null, $data);
            
            // 保存XML到文件（如果指定）
            if ($saveFile = $this->option('save')) {
                $this->saveRequestXml($saveFile, $data, $client);
            }
            
            return $result['success'] ? 0 : 1;
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
     * 显示测试数据
     */
    protected function displayData(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'OrderGuests') {
                $this->line("  {$key}:");
                if (isset($value['OrderGuest']) && is_array($value['OrderGuest'])) {
                    foreach ($value['OrderGuest'] as $index => $guest) {
                        $this->line("    [客人 " . ($index + 1) . "]");
                        $this->line("      姓名: " . ($guest['Name'] ?? ''));
                        $this->line("      身份证: " . ($guest['IdCode'] ?? ''));
                    }
                }
            } elseif ($key === 'Amount') {
                $this->line("  {$key}: " . ($value / 100) . " 元 (" . $value . " 分)");
            } else {
                $this->line("  {$key}: " . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value));
            }
        }
    }

    /**
     * 显示测试结果
     */
    protected function displayResult(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $this->info("[测试结果]");
        $this->newLine();

        if ($result['success'] ?? false) {
            $this->info("  ✓ 成功");
            $this->newLine();
            
            if (isset($result['data'])) {
                $this->line("  响应数据:");
                if ($this->option('json')) {
                    $this->line(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    if (is_object($result['data']) && isset($result['data']->OrderId)) {
                        $this->line("    订单ID: " . (string)$result['data']->OrderId);
                    }
                    if (is_object($result['data']) && isset($result['data']->ResultCode)) {
                        $this->line("    结果码: " . (string)$result['data']->ResultCode);
                    }
                    if (is_object($result['data']) && isset($result['data']->Message)) {
                        $this->line("    消息: " . (string)$result['data']->Message);
                    }
                }
            }
        } else {
            $this->error("  ✗ 失败");
            $this->newLine();
            
            $errorMessage = $result['message'] ?? '未知错误';
            $this->error("  错误信息: {$errorMessage}");
            
            if (isset($result['data'])) {
                if (is_object($result['data'])) {
                    $resultCode = (string)($result['data']->ResultCode ?? '');
                    $message = (string)($result['data']->Message ?? '');
                    
                    if ($resultCode) {
                        $this->line("  错误码: {$resultCode}");
                    }
                    if ($message && $message !== $errorMessage) {
                        $this->line("  详细消息: {$message}");
                    }
                }
            }
            
            $this->newLine();
            $this->info("[诊断信息]");
            $this->diagnoseError($result, $order, $manualData);
        }
        
        $this->newLine();
        $this->line("详细日志请查看: storage/logs/laravel.log");
    }

    /**
     * 诊断错误
     */
    protected function diagnoseError(array $result, ?Order $order = null, ?array $manualData = null): void
    {
        $errorMessage = $result['message'] ?? '';
        
        if (strpos($errorMessage, '入住人不能为空') !== false) {
            $this->warn("  问题: 横店返回'入住人不能为空'");
            $this->newLine();
            
            if ($order) {
                $guestInfo = $order->guest_info;
                $this->line("  订单客人信息:");
                $this->line("    " . json_encode($guestInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $this->newLine();
                
                if (empty($guestInfo) || !is_array($guestInfo)) {
                    $this->error("    ✗ 客人信息为空或格式错误");
                } else {
                    $this->line("    ✓ 客人信息存在");
                    foreach ($guestInfo as $index => $guest) {
                        $name = $guest['name'] ?? $guest['Name'] ?? '';
                        $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            } elseif ($manualData) {
                $orderGuests = $manualData['OrderGuests']['OrderGuest'] ?? [];
                $this->line("  测试数据客人信息:");
                if (empty($orderGuests)) {
                    $this->error("    ✗ 客人信息为空");
                } else {
                    foreach ($orderGuests as $index => $guest) {
                        $name = $guest['Name'] ?? '';
                        $idCode = $guest['IdCode'] ?? '';
                        
                        $this->line("    客人 " . ($index + 1) . ":");
                        $this->line("      姓名: " . ($name ?: '(空)'));
                        $this->line("      身份证: " . ($idCode ?: '(空)'));
                        
                        if (empty($name) || empty($idCode)) {
                            $this->error("      ✗ 姓名或身份证为空");
                        } else {
                            $this->line("      ✓ 信息完整");
                        }
                    }
                }
            }
            
            $this->newLine();
            $this->line("  可能原因:");
            $this->line("    1. XML中OrderGuests节点格式不正确");
            $this->line("    2. OrderGuest节点的Name或IdCode字段为空或格式错误");
            $this->line("    3. 横店系统验证逻辑变更");
            $this->line("    4. 认证信息不正确，导致请求被拒绝");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查日志中的XML请求内容");
            $this->line("    2. 确认客人信息的字段名是否正确（Name/IdCode）");
            $this->line("    3. 确认认证信息是否正确（用户名/密码）");
            $this->line("    4. 联系横店技术支持确认接口要求");
        } elseif (strpos($errorMessage, '配置不存在') !== false) {
            $this->warn("  问题: 资源配置不存在");
            $this->newLine();
            $this->line("  建议:");
            $this->line("    1. 检查数据库中是否有 resource_configs 记录");
            $this->line("    2. 检查 .env 文件中是否有 HENGDIAN_* 配置");
            $this->line("    3. 确认景区是否配置了资源方");
        } else {
            $this->line("  错误信息: {$errorMessage}");
            $this->line("  请查看详细日志获取更多信息");
        }
    }

    /**
     * 保存请求XML到文件
     */
    protected function saveRequestXml(string $filename, array $data, $client): void
    {
        try {
            // 使用反射调用 buildXml 方法
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('buildXml');
            $method->setAccessible(true);
            
            $xml = $method->invoke($client, 'BookRQ', $data);
            
            $fullPath = base_path($filename);
            file_put_contents($fullPath, $xml);
            $this->info("  ✓ 请求XML已保存到: {$fullPath}");
        } catch (\Exception $e) {
            $this->warn("  ✗ 保存XML失败: " . $e->getMessage());
            $this->warn("  提示: 请检查日志中的XML请求内容");
        }
    }
}
