<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ZiwoyouProductMapping;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestZiwoyouOrder extends Command
{
    protected $signature = 'test:ziwoyou-order 
                            {order_id : 订单ID}
                            {--operation=confirm : 操作类型：confirm/cancel/query}';

    protected $description = '测试自我游订单处理';

    public function handle(): int
    {
        $orderId = $this->argument('order_id');
        $operation = $this->option('operation');

        $this->info('========================================');
        $this->info('自我游订单测试');
        $this->info('========================================');
        $this->newLine();

        // 加载订单
        $order = Order::with(['product.softwareProvider', 'product.scenicSpot', 'hotel', 'roomType'])
            ->find($orderId);

        if (!$order) {
            $this->error("订单不存在: {$orderId}");
            return 1;
        }

        // 显示订单信息
        $this->info('[订单信息]');
        $this->line("  订单ID: {$order->id}");
        $this->line("  订单号: {$order->order_no}");
        $this->line("  产品ID: {$order->product_id}");
        $this->line("  酒店ID: {$order->hotel_id}");
        $this->line("  房型ID: {$order->room_type_id}");
        $this->line("  订单状态: {$order->status->value}");
        $this->newLine();

        // 检查映射关系
        $mapping = ZiwoyouProductMapping::where('product_id', $order->product_id)
            ->where('hotel_id', $order->hotel_id)
            ->where('room_type_id', $order->room_type_id)
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            $this->info('[映射关系]');
            $this->line("  自我游产品ID: {$mapping->ziwoyou_product_id}");
            $this->newLine();
        } else {
            $this->warn('[映射关系]');
            $this->line("  未找到映射关系，订单将走手工流程");
            $this->newLine();
        }

        // 获取服务
        $this->info('[获取服务]');
        try {
            $service = ResourceServiceFactory::getService($order, 'order');
            
            if ($service) {
                $this->line("  服务类型: " . get_class($service));
                $this->newLine();

                // 执行操作
                switch ($operation) {
                    case 'confirm':
                        $this->testConfirmOrder($order, $service);
                        break;
                    case 'cancel':
                        $this->testCancelOrder($order, $service);
                        break;
                    case 'query':
                        $this->testQueryOrder($order, $service);
                        break;
                    default:
                        $this->error("未知操作: {$operation}");
                        return 1;
                }
            } else {
                $this->warn("  无法获取服务（走手工流程）");
                return 0;
            }
        } catch (\Exception $e) {
            $this->error("  获取服务失败: " . $e->getMessage());
            $this->error("  堆栈: " . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    protected function testConfirmOrder(Order $order, $service): void
    {
        $this->info('[测试接单]');
        $this->line("调用 confirmOrder()...");
        
        try {
            $result = $service->confirmOrder($order);
            
            $this->newLine();
            if ($result['success'] ?? false) {
                $this->info('✅ 接单成功');
                $this->line("  消息: " . ($result['message'] ?? ''));
                if (isset($result['data'])) {
                    $this->line("  数据: " . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            } else {
                $this->error('❌ 接单失败');
                $this->line("  消息: " . ($result['message'] ?? ''));
                if (isset($result['need_manual'])) {
                    $this->line("  需要人工处理: " . ($result['need_manual'] ? '是' : '否'));
                }
            }
            
            // 刷新订单
            $order->refresh();
            $this->newLine();
            $this->info('[订单更新]');
            $this->line("  订单状态: {$order->status->value}");
            $this->line("  资源方订单号: " . ($order->resource_order_no ?? '无'));
            $this->line("  结算金额: " . ($order->settlement_amount ?? '无'));
        } catch (\Exception $e) {
            $this->error('❌ 接单异常: ' . $e->getMessage());
            $this->error("  堆栈: " . $e->getTraceAsString());
        }
    }

    protected function testCancelOrder(Order $order, $service): void
    {
        $this->info('[测试取消订单]');
        
        if (!$order->resource_order_no) {
            $this->error("订单没有资源方订单号，无法取消");
            return;
        }
        
        $this->line("调用 cancelOrder()...");
        
        try {
            $result = $service->cancelOrder($order, '测试取消');
            
            $this->newLine();
            if ($result['success'] ?? false) {
                $this->info('✅ 取消成功');
                $this->line("  消息: " . ($result['message'] ?? ''));
            } else {
                $this->error('❌ 取消失败');
                $this->line("  消息: " . ($result['message'] ?? ''));
            }
        } catch (\Exception $e) {
            $this->error('❌ 取消异常: ' . $e->getMessage());
        }
    }

    protected function testQueryOrder(Order $order, $service): void
    {
        $this->info('[测试查询订单]');
        $this->line("调用 queryOrderStatus()...");
        
        try {
            $result = $service->queryOrderStatus($order);
            
            $this->newLine();
            if ($result['success'] ?? false) {
                $this->info('✅ 查询成功');
                $this->line("  消息: " . ($result['message'] ?? ''));
                if (isset($result['data'])) {
                    $this->line("  数据: " . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            } else {
                $this->error('❌ 查询失败');
                $this->line("  消息: " . ($result['message'] ?? ''));
            }
        } catch (\Exception $e) {
            $this->error('❌ 查询异常: ' . $e->getMessage());
        }
    }
}

