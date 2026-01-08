<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DingTalkNotificationService
{
    protected ?string $webhookUrl;

    public function __construct()
    {
        $this->webhookUrl = config('services.dingtalk.webhook_url') 
            ?? env('DINGTALK_WEBHOOK_URL');
    }

    /**
     * 检查钉钉通知是否启用
     */
    public function isEnabled(): bool
    {
        if (empty($this->webhookUrl)) {
            return false;
        }

        return env('DINGTALK_NOTIFICATION_ENABLED', true);
    }

    /**
     * 发送订单进入确认中状态通知
     */
    public function sendOrderConfirmingNotification(Order $order): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // 加载订单关联数据
        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        // 获取需要通知的用户
        $users = $this->getUsersToNotify($order);

        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        // 构建消息内容
        $message = $this->buildOrderConfirmingMessage($order);

        // 发送消息
        return $this->sendMessage($message);
    }

    /**
     * 发送订单取消申请通知
     */
    public function sendOrderCancelRequestedNotification(Order $order, array $cancelData = []): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // 加载订单关联数据
        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        // 获取需要通知的用户
        $users = $this->getUsersToNotify($order);

        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        // 构建消息内容
        $message = $this->buildOrderCancelRequestedMessage($order, $cancelData);

        // 发送消息
        return $this->sendMessage($message);
    }

    /**
     * 发送订单取消确认通知
     */
    public function sendOrderCancelConfirmedNotification(Order $order, string $cancelReason = ''): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        // 加载订单关联数据
        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        // 获取需要通知的用户
        $users = $this->getUsersToNotify($order);

        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        // 构建消息内容
        $message = $this->buildOrderCancelConfirmedMessage($order, $cancelReason);

        // 发送消息
        return $this->sendMessage($message);
    }

    /**
     * 获取需要通知的用户
     * 
     * @return \Illuminate\Support\Collection
     */
    protected function getUsersToNotify(Order $order)
    {
        $users = collect();

        // 1. 获取订单所属景区
        $scenicSpot = $order->product->scenicSpot ?? null;

        if (!$scenicSpot) {
            Log::warning('钉钉通知：订单没有关联景区', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
            ]);
            // 如果没有景区，只通知管理员
            return User::where('role', UserRole::ADMIN)
                ->where('is_active', true)
                ->get();
        }

        // 2. 获取景区关联的资源方
        $resourceProviders = $scenicSpot->resourceProviders;

        // 3. 获取资源方绑定的运营人员
        $operatorIds = collect();
        foreach ($resourceProviders as $provider) {
            $providerOperatorIds = $provider->users()
                ->where('role', UserRole::OPERATOR)
                ->where('is_active', true)
                ->pluck('id');
            $operatorIds = $operatorIds->merge($providerOperatorIds);
        }

        // 4. 获取所有管理员
        $adminIds = User::where('role', UserRole::ADMIN)
            ->where('is_active', true)
            ->pluck('id');

        // 5. 合并并去重
        $allUserIds = $operatorIds->merge($adminIds)->unique();

        if ($allUserIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $allUserIds)->get();
    }

    /**
     * 构建订单进入确认中状态的消息
     */
    protected function buildOrderConfirmingMessage(Order $order): string
    {
        $scenicSpotName = $order->product->scenicSpot->name ?? '未知景区';
        $productName = $order->product->name ?? '未知产品';
        $otaPlatformName = $order->otaPlatform->name ?? '未知平台';
        
        $totalAmount = $order->total_amount ? number_format($order->total_amount / 100, 2) : '0.00';
        $settlementAmount = $order->settlement_amount ? number_format($order->settlement_amount / 100, 2) : '0.00';

        $message = "# 📦 新订单通知\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n";
        $message .= "**产品：** {$productName}\n\n";
        $message .= "**入住信息：**\n";
        $message .= "- 入住日期：{$order->check_in_date}\n";
        $message .= "- 离店日期：{$order->check_out_date}\n";
        $message .= "- 房间数：{$order->room_count}\n";
        $message .= "- 客人数量：{$order->guest_count}\n\n";
        $message .= "**联系信息：**\n";
        $message .= "- 联系人：{$order->contact_name}\n";
        $message .= "- 联系电话：{$order->contact_phone}\n\n";
        $message .= "**订单金额：**\n";
        $message .= "- 总金额：¥{$totalAmount}元\n";
        $message .= "- 结算金额：¥{$settlementAmount}元\n\n";
        $message .= "**订单状态：** 确认中（等待处理）\n\n";
        $message .= "---\n";
        $message .= "⏰ 创建时间：{$order->created_at}\n";
        $message .= "💡 提示：订单已进入确认中状态，请及时处理";

        return $message;
    }

    /**
     * 构建订单取消申请的消息
     */
    protected function buildOrderCancelRequestedMessage(Order $order, array $cancelData = []): string
    {
        $scenicSpotName = $order->product->scenicSpot->name ?? '未知景区';
        $productName = $order->product->name ?? '未知产品';
        $otaPlatformName = $order->otaPlatform->name ?? '未知平台';
        
        $totalAmount = $order->total_amount ? number_format($order->total_amount / 100, 2) : '0.00';
        $cancelQuantity = $cancelData['quantity'] ?? $order->room_count;
        $cancelTypeLabel = $cancelData['cancel_type_label'] ?? '全部取消';

        $message = "# ⚠️ 订单取消申请\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n";
        $message .= "**产品：** {$productName}\n\n";
        $message .= "**取消信息：**\n";
        $message .= "- 取消数量：{$cancelQuantity}\n";
        $message .= "- 取消类型：{$cancelTypeLabel}\n";
        $message .= "- 申请时间：{$order->updated_at}\n\n";
        $message .= "**原订单信息：**\n";
        $message .= "- 入住日期：{$order->check_in_date}\n";
        $message .= "- 离店日期：{$order->check_out_date}\n";
        $message .= "- 房间数：{$order->room_count}\n";
        $message .= "- 订单金额：¥{$totalAmount}元\n\n";
        $message .= "**订单状态：** 申请取消中\n\n";
        $message .= "---\n";
        $message .= "⏰ 申请时间：{$order->updated_at}";

        return $message;
    }

    /**
     * 构建订单取消确认的消息
     */
    protected function buildOrderCancelConfirmedMessage(Order $order, string $cancelReason = ''): string
    {
        $scenicSpotName = $order->product->scenicSpot->name ?? '未知景区';
        $productName = $order->product->name ?? '未知产品';
        $otaPlatformName = $order->otaPlatform->name ?? '未知平台';
        
        $totalAmount = $order->total_amount ? number_format($order->total_amount / 100, 2) : '0.00';
        
        // 判断是确认还是拒绝
        $isApproved = $order->status->value === 'cancel_approved';
        $resultLabel = $isApproved ? '已确认' : '已拒绝';
        $statusLabel = $isApproved ? '取消通过' : '取消拒绝';
        $cancelledAt = $order->cancelled_at ?? $order->updated_at;

        $message = "# " . ($isApproved ? "✅" : "❌") . " 订单取消{$resultLabel}\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n";
        $message .= "**产品：** {$productName}\n\n";
        $message .= "**取消结果：** {$resultLabel}\n";
        if ($cancelReason) {
            $message .= "**取消原因：** {$cancelReason}\n";
        }
        $message .= "**取消时间：** {$cancelledAt}\n\n";
        $message .= "**原订单信息：**\n";
        $message .= "- 入住日期：{$order->check_in_date}\n";
        $message .= "- 离店日期：{$order->check_out_date}\n";
        $message .= "- 房间数：{$order->room_count}\n";
        $message .= "- 订单金额：¥{$totalAmount}元\n\n";
        $message .= "**订单状态：** {$statusLabel}\n\n";
        $message .= "---\n";
        $message .= "⏰ 确认时间：{$order->updated_at}";

        return $message;
    }

    /**
     * 发送钉钉消息
     */
    protected function sendMessage(string $message): bool
    {
        try {
            $response = Http::timeout(10)->post($this->webhookUrl, [
                'msgtype' => 'markdown',
                'markdown' => [
                    'title' => '订单通知',
                    'text' => $message,
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();
                if (($result['errcode'] ?? -1) === 0) {
                    Log::info('钉钉通知发送成功', [
                        'webhook_url' => $this->webhookUrl,
                    ]);
                    return true;
                } else {
                    Log::error('钉钉通知发送失败', [
                        'webhook_url' => $this->webhookUrl,
                        'error_code' => $result['errcode'] ?? 'unknown',
                        'error_msg' => $result['errmsg'] ?? 'unknown',
                    ]);
                    return false;
                }
            } else {
                Log::error('钉钉通知请求失败', [
                    'webhook_url' => $this->webhookUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('钉钉通知异常', [
                'webhook_url' => $this->webhookUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}

