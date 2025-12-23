<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OTA\CtripService;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestCtripConsumedNotice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ctrip:test-consumed-notice 
                            {--order= : 订单ID或携程订单号}
                            {--item-id= : 订单项编号（可选，默认使用订单的ctrip_item_id或订单ID）}
                            {--use-start-date= : 实际使用开始日期，格式：yyyy-MM-dd（可选，默认使用订单的check_in_date）}
                            {--use-end-date= : 实际使用结束日期，格式：yyyy-MM-dd（可选，默认使用订单的check_out_date）}
                            {--quantity= : 订单总份数（可选，默认使用订单的room_count）}
                            {--use-quantity= : 订单已核销总份数（可选，默认使用订单的room_count）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试携程订单核销通知';

    public function __construct(
        protected CtripService $ctripService,
        protected OrderService $orderService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orderIdentifier = $this->option('order');

        if (!$orderIdentifier) {
            $this->error('请指定订单ID或携程订单号（--order=）');
            return self::FAILURE;
        }

        // 查找订单
        $order = Order::where('id', $orderIdentifier)
            ->orWhere('ota_order_no', $orderIdentifier)
            ->first();

        if (!$order) {
            $this->error("订单不存在: {$orderIdentifier}");
            return self::FAILURE;
        }

        if (!$order->ota_order_no) {
            $this->error("订单没有携程订单号");
            return self::FAILURE;
        }

        // 获取参数
        $itemId = $this->option('item-id') ?: ($order->ctrip_item_id ?: (string) $order->id);
        $useStartDate = $this->option('use-start-date') ?: $order->check_in_date->format('Y-m-d');
        $useEndDate = $this->option('use-end-date') ?: $order->check_out_date->format('Y-m-d');
        $quantity = $this->option('quantity') ? (int) $this->option('quantity') : $order->room_count;
        $useQuantity = $this->option('use-quantity') ? (int) $this->option('use-quantity') : $order->room_count;

        // 从 guest_info 中提取出行人信息
        $passengers = [];
        if (!empty($order->guest_info) && is_array($order->guest_info)) {
            foreach ($order->guest_info as $guest) {
                if (isset($guest['passengerId'])) {
                    $passengers[] = ['passengerId' => $guest['passengerId']];
                }
            }
        }

        $this->info("准备发送核销通知...");
        $this->line("携程订单号: {$order->ota_order_no}");
        $this->line("供应商订单号: {$order->order_no}");
        $this->line("订单项编号: {$itemId}");
        $this->line("使用开始日期: {$useStartDate}");
        $this->line("使用结束日期: {$useEndDate}");
        $this->line("订单总份数: {$quantity}");
        $this->line("已核销总份数: {$useQuantity}");
        $this->line("出行人数量: " . count($passengers));

        try {
            $result = $this->ctripService->notifyOrderConsumed(
                $order->ota_order_no,
                $order->order_no,
                $itemId,
                $useStartDate,
                $useEndDate,
                $quantity,
                $useQuantity,
                $passengers
            );

            $this->info("核销通知发送成功！");
            $this->line("响应结果:");
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // 检查响应是否成功
            $isSuccess = false;
            if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
                $isSuccess = true;
            } elseif (isset($result['success']) && $result['success'] === true) {
                $isSuccess = true;
            }

            // 如果核销通知发送成功，更新订单状态为已核销（用于测试"消费后取消"流程）
            if ($isSuccess && $order->status !== OrderStatus::VERIFIED) {
                $this->info("更新订单状态为已核销...");
                $this->orderService->updateOrderStatus(
                    $order,
                    OrderStatus::VERIFIED,
                    '测试：核销通知发送成功，订单已核销'
                );
                $this->info("订单状态已更新为已核销（VERIFIED）");
                $this->line("现在可以等待携程调用 CancelOrder 接口进行\"消费后取消\"测试");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("核销通知发送失败: " . $e->getMessage());
            Log::error('携程核销通知测试失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}

