<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\ScenicSpotDingTalkConfig;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DingTalkNotificationService
{
    protected ?string $defaultWebhookUrl;

    public function __construct()
    {
        $this->defaultWebhookUrl = config('services.dingtalk.webhook_url')
            ?? env('DINGTALK_WEBHOOK_URL');
    }

    /**
     * 检查钉钉通知是否启用
     */
    public function isEnabled(): bool
    {
        $enabled = env('DINGTALK_NOTIFICATION_ENABLED', true);
        if (!$enabled) {
            Log::info('钉钉通知：已禁用', [
                'config_key' => 'DINGTALK_NOTIFICATION_ENABLED',
                'config_value' => env('DINGTALK_NOTIFICATION_ENABLED'),
            ]);
            return false;
        }

        return true;
    }

    /**
     * 发送订单进入确认中状态通知
     */
    public function sendOrderConfirmingNotification(Order $order): bool
    {
        Log::info('DingTalkNotificationService: 开始发送订单确认中通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
        ]);

        if (!$this->isEnabled()) {
            Log::warning('DingTalkNotificationService: 钉钉通知未启用', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        // 加载订单关联数据
        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        Log::debug('DingTalkNotificationService: 订单关联数据已加载', [
            'order_id' => $order->id,
            'has_product' => $order->product !== null,
            'has_scenic_spot' => $order->product?->scenicSpot !== null,
            'has_ota_platform' => $order->otaPlatform !== null,
        ]);

        // 获取需要通知的用户
        $users = $this->getUsersToNotify($order);

        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'scenic_spot_id' => $order->product?->scenic_spot_id,
            ]);
            return false;
        }

        Log::info('DingTalkNotificationService: 找到需要通知的用户', [
            'order_id' => $order->id,
            'user_count' => $users->count(),
            'user_ids' => $users->pluck('id')->toArray(),
            'user_roles' => $users->pluck('role.value')->toArray(),
        ]);

        // 构建消息内容
        $message = $this->buildOrderConfirmingMessage($order);

        Log::debug('DingTalkNotificationService: 消息内容已构建', [
            'order_id' => $order->id,
            'message_length' => strlen($message),
        ]);

        // 发送消息
        return $this->sendMessageForOrder($order, $message, '📦 新订单通知');
    }

    /**
     * 发送订单自动接单通知（库存充裕自动接单，标题标明「自动接单」）
     */
    public function sendOrderAutoConfirmedNotification(Order $order): bool
    {
        Log::info('DingTalkNotificationService: 开始发送订单自动接单通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
        ]);

        if (!$this->isEnabled()) {
            Log::warning('DingTalkNotificationService: 钉钉通知未启用', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        $users = $this->getUsersToNotify($order);
        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'scenic_spot_id' => $order->product?->scenic_spot_id,
            ]);
            return false;
        }

        $message = $this->buildOrderAutoConfirmedMessage($order);
        return $this->sendMessageForOrder($order, $message, '📦 新订单通知（自动接单）');
    }

    /**
     * 发送订单取消申请通知
     */
    public function sendOrderCancelRequestedNotification(Order $order, array $cancelData = []): bool
    {
        Log::info('DingTalkNotificationService: 开始发送订单取消申请通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'cancel_data' => $cancelData,
        ]);

        if (!$this->isEnabled()) {
            Log::warning('DingTalkNotificationService: 钉钉通知未启用', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        // 加载订单关联数据
        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        Log::debug('DingTalkNotificationService: 订单关联数据已加载', [
            'order_id' => $order->id,
            'has_product' => $order->product !== null,
            'has_scenic_spot' => $order->product?->scenicSpot !== null,
            'has_ota_platform' => $order->otaPlatform !== null,
        ]);

        // 获取需要通知的用户
        $users = $this->getUsersToNotify($order);

        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'scenic_spot_id' => $order->product?->scenic_spot_id,
            ]);
            return false;
        }

        Log::info('DingTalkNotificationService: 找到需要通知的用户', [
            'order_id' => $order->id,
            'user_count' => $users->count(),
            'user_ids' => $users->pluck('id')->toArray(),
            'user_roles' => $users->pluck('role.value')->toArray(),
        ]);

        // 构建消息内容
        $message = $this->buildOrderCancelRequestedMessage($order, $cancelData);

        Log::debug('DingTalkNotificationService: 消息内容已构建', [
            'order_id' => $order->id,
            'message_length' => strlen($message),
        ]);

        // 发送消息
        return $this->sendMessageForOrder($order, $message, '⚠️ 订单取消申请');
    }

    /**
     * 发送订单取消确认通知
     */
    public function sendOrderCancelConfirmedNotification(Order $order, string $cancelReason = ''): bool
    {
        Log::info('DingTalkNotificationService: 开始发送订单取消确认通知', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'order_status' => $order->status->value,
            'cancel_reason' => $cancelReason,
        ]);

        if (!$this->isEnabled()) {
            Log::warning('DingTalkNotificationService: 钉钉通知未启用', [
                'order_id' => $order->id,
            ]);
            return false;
        }

        // 加载订单关联数据
        $order->load([
            'otaPlatform',
            'product.scenicSpot',
            'hotel',
            'roomType'
        ]);

        Log::debug('DingTalkNotificationService: 订单关联数据已加载', [
            'order_id' => $order->id,
            'has_product' => $order->product !== null,
            'has_scenic_spot' => $order->product?->scenicSpot !== null,
            'has_ota_platform' => $order->otaPlatform !== null,
        ]);

        // 获取需要通知的用户
        $users = $this->getUsersToNotify($order);

        if ($users->isEmpty()) {
            Log::warning('钉钉通知：没有找到需要通知的用户', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'scenic_spot_id' => $order->product?->scenic_spot_id,
            ]);
            return false;
        }

        Log::info('DingTalkNotificationService: 找到需要通知的用户', [
            'order_id' => $order->id,
            'user_count' => $users->count(),
            'user_ids' => $users->pluck('id')->toArray(),
            'user_roles' => $users->pluck('role.value')->toArray(),
        ]);

        // 1. 动态生成标题
        $isApproved = $order->status->value === 'cancel_approved';
        $title = $isApproved ? '✅ 订单取消成功' : '❌ 订单取消被拒绝';


        // 构建消息内容
        $message = $this->buildOrderCancelConfirmedMessage($order, $cancelReason);

        Log::debug('DingTalkNotificationService: 消息内容已构建', [
            'order_id' => $order->id,
            'message_length' => strlen($message),
        ]);

            
        // 发送消息
        return $this->sendMessageForOrder($order, $message, $title);
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
            Log::warning('钉钉通知：订单没有关联景区，仅通知管理员', [
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'has_product' => $order->product !== null,
            ]);
            // 如果没有景区，只通知管理员
            $admins = User::where('role', UserRole::ADMIN)
                ->where('is_active', true)
                ->get();
            
            Log::info('钉钉通知：找到管理员用户', [
                'order_id' => $order->id,
                'admin_count' => $admins->count(),
                'admin_ids' => $admins->pluck('id')->toArray(),
            ]);
            
            return $admins;
        }

        // 2. 获取景区关联的资源方
        $resourceProviders = $scenicSpot->resourceProviders;

        // 3. 获取资源方绑定的运营人员
        $operatorIds = collect();
        foreach ($resourceProviders as $provider) {
            // 明确指定选择users表的字段，避免SQL字段歧义
            $providerOperatorIds = $provider->users()
                ->select('users.id')  // 明确指定表名，避免字段歧义
                ->where('role', UserRole::OPERATOR)
                ->where('is_active', true)
                ->pluck('users.id');
            $operatorIds = $operatorIds->merge($providerOperatorIds);
        }

        // 4. 获取所有管理员
        $adminIds = User::where('role', UserRole::ADMIN)
            ->where('is_active', true)
            ->pluck('id');

        // 5. 合并并去重
        $allUserIds = $operatorIds->merge($adminIds)->unique();

        Log::debug('钉钉通知：用户查询结果', [
            'order_id' => $order->id,
            'scenic_spot_id' => $scenicSpot->id,
            'scenic_spot_name' => $scenicSpot->name,
            'resource_provider_count' => $resourceProviders->count(),
            'operator_ids' => $operatorIds->toArray(),
            'admin_ids' => $adminIds->toArray(),
            'all_user_ids' => $allUserIds->toArray(),
        ]);

        if ($allUserIds->isEmpty()) {
            Log::warning('钉钉通知：未找到任何用户（运营人员和管理员）', [
                'order_id' => $order->id,
                'scenic_spot_id' => $scenicSpot->id,
                'resource_provider_count' => $resourceProviders->count(),
            ]);
            return collect();
        }

        $users = User::whereIn('id', $allUserIds)->get();

        Log::debug('钉钉通知：用户查询完成', [
            'order_id' => $order->id,
            'user_count' => $users->count(),
            'user_details' => $users->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role->value,
            ])->toArray(),
        ]);

        return $users;
    }

    /**
     * 构建订单进入确认中状态的消息
     */
    protected function buildOrderConfirmingMessage(Order $order): string
    {
        $scenicSpotName = $order->product->scenicSpot->name ?? '未知景区';
        $productName = $order->product->name ?? '未知产品';
        $otaPlatformName = $order->otaPlatform->name ?? '未知平台';
        $hotelName = $order->hotel->name ?? '未知酒店';
        $roomTypeName = $order->roomType->name ?? '未知房型';

        // 价格单位：数据库存储已经是元，直接使用
        $totalAmount = $order->total_amount ? number_format($order->total_amount, 2) : '0.00';
        $settlementAmount = $order->settlement_amount ? number_format($order->settlement_amount, 2) : '0.00';

        $message = "# 📦 新订单通知\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n";
        $message .= "**产品：** {$productName}\n";
        $message .= "**酒店：** {$hotelName}\n";
        $message .= "**房型：** {$roomTypeName}\n\n";
        $message .= "**入住信息：**\n";
        $message .= "- 入住日期：{$order->check_in_date->format('Y-m-d')}\n";
        $message .= "- 离店日期：{$order->check_out_date->format('Y-m-d')}\n";
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
     * 构建订单自动接单通知的消息
     */
    protected function buildOrderAutoConfirmedMessage(Order $order): string
    {
        $scenicSpotName = $order->product->scenicSpot->name ?? '未知景区';
        $productName = $order->product->name ?? '未知产品';
        $otaPlatformName = $order->otaPlatform->name ?? '未知平台';
        $hotelName = $order->hotel->name ?? '未知酒店';
        $roomTypeName = $order->roomType->name ?? '未知房型';
        $totalAmount = $order->total_amount ? number_format($order->total_amount, 2) : '0.00';
        $settlementAmount = $order->settlement_amount ? number_format($order->settlement_amount, 2) : '0.00';

        $message = "# 📦 新订单通知（自动接单）\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n";
        $message .= "**产品：** {$productName}\n";
        $message .= "**酒店：** {$hotelName}\n";
        $message .= "**房型：** {$roomTypeName}\n\n";
        $message .= "**入住信息：**\n";
        $message .= "- 入住日期：{$order->check_in_date->format('Y-m-d')}\n";
        $message .= "- 离店日期：{$order->check_out_date->format('Y-m-d')}\n";
        $message .= "- 房间数：{$order->room_count}\n";
        $message .= "- 客人数量：{$order->guest_count}\n\n";
        $message .= "**联系信息：**\n";
        $message .= "- 联系人：{$order->contact_name}\n";
        $message .= "- 联系电话：{$order->contact_phone}\n\n";
        $message .= "**订单金额：**\n";
        $message .= "- 总金额：¥{$totalAmount}元\n";
        $message .= "- 结算金额：¥{$settlementAmount}元\n\n";
        $message .= "**订单状态：** 已自动接单\n\n";
        $message .= "---\n";
        $message .= "⏰ 创建时间：{$order->created_at}\n";
        $message .= "💡 提示：系统已自动接单，无需处理";

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
        
        // 价格单位：数据库存储已经是元，直接使用
        $totalAmount = $order->total_amount ? number_format($order->total_amount, 2) : '0.00';
        $cancelQuantity = $cancelData['quantity'] ?? $order->room_count;
        $cancelTypeLabel = $cancelData['cancel_type_label'] ?? '全部取消';

        $message = "# ⚠️ 订单取消申请\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n\n";
        $message .= "**产品：** {$productName}\n\n";
        $message .= "**取消信息：**\n";
        $message .= "- 取消数量：{$cancelQuantity}\n";
        $message .= "- 取消类型：{$cancelTypeLabel}\n";
        $message .= "- 申请时间：{$order->updated_at}\n\n";
        $message .= "**原订单信息：**\n";
        $message .= "- 联系人：{$order->contact_name}\n";
        $message .= "- 入住日期：{$order->check_in_date->format('Y-m-d')}\n";
        $message .= "- 离店日期：{$order->check_out_date->format('Y-m-d')}\n";
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
        
        // 价格单位：数据库存储已经是元，直接使用
        $totalAmount = $order->total_amount ? number_format($order->total_amount, 2) : '0.00';
        
        // 判断是确认还是拒绝
        $isApproved = $order->status->value === 'cancel_approved';
        $resultLabel = $isApproved ? '已确认' : '已拒绝';
        $statusLabel = $isApproved ? '取消通过' : '取消拒绝';
        $cancelledAt = $order->cancelled_at ?? $order->updated_at;

        $message = "# " . ($isApproved ? "✅" : "❌") . " 订单取消{$resultLabel}\n\n";
        $message .= "**订单号：** {$order->order_no}\n";
        $message .= "**OTA平台：** {$otaPlatformName}\n\n";
        $message .= "**OTA订单号：** {$order->ota_order_no}\n\n";
        $message .= "**景区：** {$scenicSpotName}\n\n";
        $message .= "**产品：** {$productName}\n\n";
        $message .= "**取消结果：** {$resultLabel}\n\n";
        if ($cancelReason) {
            $message .= "**取消原因：** {$cancelReason}\n\n";
        }
        $message .= "**取消时间：** {$cancelledAt}\n\n";
        $message .= "**原订单信息：**\n";
        $message .= "- 联系人：{$order->contact_name}\n";
        $message .= "- 入住日期：{$order->check_in_date->format('Y-m-d')}\n";
        $message .= "- 离店日期：{$order->check_out_date->format('Y-m-d')}\n";
        $message .= "- 房间数：{$order->room_count}\n";
        $message .= "- 订单金额：¥{$totalAmount}元\n\n";
        $message .= "**订单状态：** {$statusLabel}\n\n";
        $message .= "---\n";
        $message .= "⏰ 确认时间：{$order->updated_at}";

        return $message;
    }

    protected function sendMessageForOrder(Order $order, string $message, string $title = '订单通知'): bool
    {
        $resolution = $this->resolveWebhookUrlForOrder($order);

        if ($resolution['should_send'] === false) {
            return false;
        }

        $webhookUrl = $resolution['webhook_url'] ?? null;
        if (empty($webhookUrl)) {
            Log::warning('钉钉通知：未解析到可用Webhook，跳过发送', [
                'order_id' => $order->id,
                'scenic_spot_id' => $resolution['scenic_spot_id'] ?? null,
                'reason' => $resolution['reason'] ?? 'unknown',
                'default_webhook_configured' => !empty($this->defaultWebhookUrl),
            ]);
            return false;
        }

        return $this->sendMessage($webhookUrl, $message, $title, [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'scenic_spot_id' => $resolution['scenic_spot_id'] ?? null,
            'route' => $resolution['route'] ?? null,
        ]);
    }

    protected function resolveWebhookUrlForOrder(Order $order): array
    {
        $scenicSpotId = $order->product?->scenic_spot_id ?? $order->hotel?->scenic_spot_id;
        $default = $this->defaultWebhookUrl;

        if (!Schema::hasTable('scenic_spot_dingtalk_configs')) {
            return [
                'should_send' => true,
                'scenic_spot_id' => $scenicSpotId,
                'webhook_url' => $default,
                'route' => 'default',
                'reason' => empty($default) ? 'default_webhook_not_configured' : 'table_not_exists_fallback_default',
            ];
        }

        if (empty($scenicSpotId)) {
            return [
                'should_send' => true,
                'scenic_spot_id' => null,
                'webhook_url' => $default,
                'route' => 'default',
                'reason' => empty($default) ? 'default_webhook_not_configured' : 'no_scenic_spot',
            ];
        }

        $config = null;
        $scenicSpot = $order->product?->scenicSpot;
        if ($scenicSpot && $scenicSpot->relationLoaded('dingtalkConfig')) {
            $config = $scenicSpot->dingtalkConfig;
        } elseif ($scenicSpot) {
            $config = $scenicSpot->dingtalkConfig()->first();
        } else {
            $config = ScenicSpotDingTalkConfig::where('scenic_spot_id', $scenicSpotId)->first();
        }

        if ($config) {
            if (!$config->enabled) {
                Log::info('钉钉通知：景区专属配置已禁用，跳过发送', [
                    'order_id' => $order->id,
                    'scenic_spot_id' => $scenicSpotId,
                ]);
                return [
                    'should_send' => false,
                    'scenic_spot_id' => $scenicSpotId,
                    'webhook_url' => null,
                    'route' => 'disabled',
                    'reason' => 'scenic_spot_disabled',
                ];
            }

            $spotWebhook = $config->webhook_url;
            if (!empty($spotWebhook)) {
                return [
                    'should_send' => true,
                    'scenic_spot_id' => $scenicSpotId,
                    'webhook_url' => $spotWebhook,
                    'route' => 'scenic_spot',
                    'reason' => 'scenic_spot_configured',
                ];
            }
        }

        return [
            'should_send' => true,
            'scenic_spot_id' => $scenicSpotId,
            'webhook_url' => $default,
            'route' => 'default',
            'reason' => empty($default) ? 'default_webhook_not_configured' : 'fallback_to_default',
        ];
    }

    /**
     * 发送钉钉消息
     */
    protected function sendMessage(string $webhookUrl, string $message, string $title = '订单通知', array $context = []): bool
    {
        // 脱敏处理Webhook URL（只显示前30个字符）
        $maskedUrl = $this->maskWebhookUrl($webhookUrl);

        try {
            Log::debug('DingTalkNotificationService: 准备发送钉钉消息', [
                'webhook_url_masked' => $maskedUrl,
                'message_length' => strlen($message),
                'context' => $context,
            ]);

            $response = Http::timeout(10)->post($webhookUrl, [
                'msgtype' => 'markdown',
                'markdown' => [
                    'title' => $title,
                    'text' => $message,
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();
                if (($result['errcode'] ?? -1) === 0) {
                    Log::info('钉钉通知发送成功', [
                        'webhook_url_masked' => $maskedUrl,
                        'message_length' => strlen($message),
                        'context' => $context,
                    ]);
                    return true;
                } else {
                    Log::error('钉钉通知发送失败（API返回错误）', [
                        'webhook_url_masked' => $maskedUrl,
                        'error_code' => $result['errcode'] ?? 'unknown',
                        'error_msg' => $result['errmsg'] ?? 'unknown',
                        'full_response' => $result,
                        'message_length' => strlen($message),
                        'context' => $context,
                    ]);
                    return false;
                }
            } else {
                $responseBody = $response->body();
                Log::error('钉钉通知请求失败（HTTP错误）', [
                    'webhook_url_masked' => $maskedUrl,
                    'http_status' => $response->status(),
                    'response_body' => $responseBody,
                    'message_length' => strlen($message),
                    'context' => $context,
                ]);
                return false;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('钉钉通知异常（网络连接失败）', [
                'webhook_url_masked' => $maskedUrl,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'message_length' => strlen($message),
                'context' => $context,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('钉钉通知异常', [
                'webhook_url_masked' => $maskedUrl,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'message_length' => strlen($message),
                'context' => $context,
            ]);
            return false;
        }
    }

    /**
     * 脱敏处理Webhook URL
     */
    protected function maskWebhookUrl(?string $url): string
    {
        if (empty($url)) {
            return '(empty)';
        }

        // 提取URL的基础部分和token部分
        $parsed = parse_url($url);
        if (!$parsed) {
            return substr($url, 0, 30) . '...';
        }

        $base = ($parsed['scheme'] ?? '') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
        $query = $parsed['query'] ?? '';

        // 如果包含access_token，只显示前10个字符
        if (preg_match('/access_token=([^&]+)/', $query, $matches)) {
            $token = $matches[1];
            $maskedToken = substr($token, 0, 10) . '...' . substr($token, -4);
            $query = preg_replace('/access_token=[^&]+/', 'access_token=' . $maskedToken, $query);
        }

        return $base . ($query ? '?' . $query : '');
    }
}

