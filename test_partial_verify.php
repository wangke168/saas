<?php

/**
 * 美团订单部分核销测试脚本
 * 
 * 使用方法：
 * 1. 修改下面的配置参数
 * 2. 运行：php test_partial_verify.php
 * 
 * 注意：此脚本会跳过美团核销通知，仅用于测试
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Order;
use App\Services\OrderOperationService;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;

// ============================================
// 配置参数（请修改为实际值）
// ============================================
$otaOrderNo = '5017024523437487103';  // 美团订单号（ota_order_no）
$useQuantity = 1;  // 核销数量（1表示核销1张票）

// ============================================
// 执行核销
// ============================================

echo "========================================\n";
echo "美团订单部分核销测试脚本\n";
echo "========================================\n\n";

// 查询订单
echo "1. 查询订单...\n";
$order = Order::where('ota_order_no', $otaOrderNo)->first();

if (!$order) {
    echo "❌ 订单不存在：{$otaOrderNo}\n";
    exit(1);
}

echo "✅ 找到订单：\n";
echo "   - 订单ID：{$order->id}\n";
echo "   - 订单号：{$order->order_no}\n";
echo "   - 美团订单号：{$order->ota_order_no}\n";
echo "   - 订单状态：{$order->status->label()}\n";
echo "   - 订单总票数：{$order->room_count}\n";
echo "   - 入住日期：{$order->check_in_date->format('Y-m-d')}\n";
echo "   - 离店日期：{$order->check_out_date->format('Y-m-d')}\n";
echo "   - 实名制类型：{$order->real_name_type}\n";
echo "\n";

// 检查订单状态
if ($order->status !== OrderStatus::CONFIRMED) {
    echo "❌ 订单状态不允许核销，当前状态：{$order->status->label()}\n";
    echo "   提示：订单状态必须是 '已确认' 才能核销\n";
    exit(1);
}

// 检查核销数量
if ($useQuantity > $order->room_count) {
    echo "❌ 核销数量不能大于订单总票数\n";
    echo "   - 订单总票数：{$order->room_count}\n";
    echo "   - 核销数量：{$useQuantity}\n";
    exit(1);
}

// 准备核销数据
echo "2. 准备核销数据...\n";
$data = [
    'use_start_date' => $order->check_in_date->format('Y-m-d'),
    'use_end_date' => $order->check_out_date->format('Y-m-d'),
    'use_quantity' => $useQuantity,
    'passengers' => [],
    'vouchers' => [],
];

// 如果是实名制订单，传入第一个证件信息
if ($order->real_name_type === 1 && !empty($order->credential_list)) {
    echo "   检测到实名制订单，提取证件信息...\n";
    $firstCredential = $order->credential_list[0] ?? null;
    if ($firstCredential) {
        $credentialNo = $firstCredential['credentialNo'] ?? '';
        $credentialType = $firstCredential['credentialType'] ?? 0;
        $data['passengers'] = [
            [
                'credentialNo' => $credentialNo,
                'credentialType' => $credentialType,
            ],
        ];
        echo "   - 已核销证件号：{$credentialNo}\n";
    }
}

echo "\n";

// 设置环境变量跳过美团通知（测试模式）
echo "3. 设置测试模式（跳过美团通知）...\n";
putenv('MEITUAN_SKIP_VERIFY_NOTIFICATION=true');
echo "   ✅ 已设置 MEITUAN_SKIP_VERIFY_NOTIFICATION=true\n";
echo "\n";

// 执行核销
echo "4. 执行核销...\n";
try {
    $service = app(OrderOperationService::class);
    $result = $service->verifyOrder($order, $data, null);

    if ($result['success']) {
        echo "✅ 核销成功！\n";
        echo "\n";
        echo "核销结果：\n";
        echo "   - 订单ID：{$order->id}\n";
        echo "   - 核销数量：{$useQuantity}\n";
        echo "   - 订单状态：{$order->status->label()}\n";
        echo "\n";
        
        // 查询核销日志
        echo "5. 查询核销日志...\n";
        $logs = \App\Models\OrderLog::where('order_id', $order->id)
            ->where('to_status', OrderStatus::VERIFIED->value)
            ->orderBy('created_at', 'desc')
            ->get();
        
        if ($logs->isNotEmpty()) {
            $latestLog = $logs->first();
            echo "   ✅ 找到核销日志：\n";
            echo "      - 日志ID：{$latestLog->id}\n";
            echo "      - 原状态：{$latestLog->from_status}\n";
            echo "      - 新状态：{$latestLog->to_status}\n";
            echo "      - 备注：{$latestLog->remark}\n";
            echo "      - 创建时间：{$latestLog->created_at}\n";
            
            // 验证备注中是否包含核销数量
            if (strpos($latestLog->remark, '核销数量：' . $useQuantity) !== false) {
                echo "      ✅ 备注中包含核销数量\n";
            } else {
                echo "      ⚠️  备注中可能不包含核销数量\n";
            }
        } else {
            echo "   ⚠️  未找到核销日志\n";
        }
        
        echo "\n";
        echo "========================================\n";
        echo "核销完成！\n";
        echo "========================================\n";
        echo "\n";
        echo "下一步：\n";
        echo "1. 等待2小时后，美团会自动调用订单查询接口\n";
        echo "2. 查看日志验证订单查询接口返回的 usedQuantity 是否为 {$useQuantity}\n";
        echo "3. 在美团后台验证订单状态是否自动更新为部分核销\n";
        echo "\n";
        echo "查看日志命令：\n";
        echo "   tail -f storage/logs/laravel.log | grep '美团订单查询'\n";
        echo "\n";
        
    } else {
        echo "❌ 核销失败：{$result['message']}\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "❌ 核销异常：{$e->getMessage()}\n";
    echo "\n";
    echo "错误详情：\n";
    echo $e->getTraceAsString();
    exit(1);
}

