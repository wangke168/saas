<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Client\HengdianClient;
use App\Models\ResourceConfig;
use App\Models\SoftwareProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 横店酒店订单接口联调命令（对齐飞猪 test:fliggy-product / test:fliggy-order 的用法）。
 *
 * 文档：storage/docs/hengdian/api.pdf
 * 测试环境：http://testhotel.hengdianworld.com/Interface/hotel_order.aspx
 *
 * 示例：
 *   php artisan test:hengdian-api --validate --hotel-id=001 --room-type=标准间
 *   php artisan test:hengdian-api --query --ota-order-id=13877841232130314499122979
 *   php artisan test:hengdian-api --cancel --ota-order-id=13877841232130314499122979
 *   php artisan test:hengdian-api --subscribe --notify-url=https://xxx/api/webhooks/resource/hengdian/inventory --hotel-id=001 --room-type=标准间
 *   php artisan test:hengdian-api --push-self --base-url=http://127.0.0.1:8000
 *
 * 下单请使用：php artisan test:hengdian-book（构建完整 OrderGuests 等）
 */
class TestHengdianApi extends Command
{
    protected $signature = 'test:hengdian-api
                            {--validate : 可订检查 ValidateRQ}
                            {--query : 订单查询 QueryStatusRQ}
                            {--cancel : 取消订单 CancelRQ}
                            {--subscribe : 房态订阅 SubscribeRoomStatusRQ}
                            {--unsubscribe : 与 --subscribe 联用，取消订阅}
                            {--push-self : 向本机 Webhook 发送模拟房态推送 RoomStatus（需 local/testing）}
                            {--all-readonly : 执行 ValidateRQ；若同时提供 --ota-order-id 则再执行 QueryStatusRQ}
                            {--ota-order-id= : Query/Cancel 的 OtaOrderId}
                            {--hotel-id= : Validate/Subscribe 的酒店编号，如 001}
                            {--room-type= : Validate/Subscribe 的房型名称}
                            {--package-id= : Validate 的套餐 ID，空为单酒店}
                            {--check-in= : 入住日期 YYYY-MM-DD}
                            {--check-out= : 离店日期 YYYY-MM-DD}
                            {--room-num=1 : 房间数}
                            {--customer-number=2 : 入住人数}
                            {--payment-type=1 : 支付方式，文档：1 预付 5 面付 6 信用住（Validate 用）}
                            {--notify-url= : 订阅回调地址，默认取 HENGDIAN_WEBHOOK_URL}
                            {--cancel-reason=用户取消 : Cancel 原因}
                            {--base-url= : --push-self 时的站点根 URL，默认 APP_URL}
                            {--json : 输出 JSON}';

    protected $description = '横店 XML 接口联调：Validate / Query / Cancel / Subscribe（下单用 test:hengdian-book）';

    public function handle(): int
    {
        $this->info('横店接口联调 test:hengdian-api（文档：storage/docs/hengdian/api.pdf）');
        $this->newLine();

        if ($this->option('push-self')) {
            return $this->runPushSelf();
        }

        $config = $this->getResourceConfig();
        if ($config === null) {
            $this->error('未找到横店配置：请在 .env 设置 HENGDIAN_API_URL / USERNAME / PASSWORD，或在库中配置 api_type=hengdian 的资源方。');

            return self::FAILURE;
        }

        try {
            $client = new HengdianClient($config);
        } catch (\Throwable $e) {
            $this->error('创建 HengdianClient 失败：' . $e->getMessage());

            return self::FAILURE;
        }

        $ran = false;
        $exit = self::SUCCESS;

        if ($this->option('all-readonly')) {
            $ran = true;
            $exit = max($exit, $this->runValidate($client));
            if ($this->option('ota-order-id')) {
                $exit = max($exit, $this->runQuery($client));
            } else {
                $this->warn('--all-readonly：未提供 --ota-order-id，已跳过 QueryStatusRQ');
            }
        }

        if ($this->option('validate')) {
            $ran = true;
            $exit = max($exit, $this->runValidate($client));
        }

        if ($this->option('query')) {
            $ran = true;
            $exit = max($exit, $this->runQuery($client));
        }

        if ($this->option('cancel')) {
            $ran = true;
            $exit = max($exit, $this->runCancel($client));
        }

        if ($this->option('subscribe')) {
            $ran = true;
            $exit = max($exit, $this->runSubscribe($client));
        }

        if (!$ran) {
            $this->warn('未指定操作，请使用 --validate、--query、--cancel、--subscribe、--all-readonly 或 --push-self');
            $this->line('下单请执行：php artisan test:hengdian-book');

            return self::INVALID;
        }

        $this->newLine();
        $this->info('完成。详细请求/响应见 storage/logs/laravel.log');

        return $exit;
    }

    protected function getResourceConfig(): ?ResourceConfig
    {
        $url = config('services.hengdian.api_url');
        $user = config('services.hengdian.username');
        $pass = config('services.hengdian.password');

        if ($url && $user && $pass) {
            $config = new ResourceConfig;
            $config->username = $user;
            $config->password = $pass;
            $config->environment = 'production';
            $config->is_active = true;
            $config->extra_config = [
                'api_url_override' => $url,
                'sync_mode' => [
                    'inventory' => 'manual',
                    'price' => 'manual',
                    'order' => 'manual',
                ],
            ];

            return $config;
        }

        $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
        if ($provider === null) {
            return null;
        }

        return ResourceConfig::with('softwareProvider')
            ->where('software_provider_id', $provider->id)
            ->where('is_active', true)
            ->first();
    }

    protected function runValidate(HengdianClient $client): int
    {
        $this->info('=== ValidateRQ 可订检查 ===');

        $hotelId = $this->option('hotel-id') ?: $this->ask('HotelId（横店酒店编号）', '001');
        $roomType = $this->option('room-type') ?: $this->ask('RoomType（房型名称）', '标准间');
        $checkIn = $this->option('check-in') ?: $this->ask('CheckIn', date('Y-m-d', strtotime('+7 days')));
        $checkOut = $this->option('check-out') ?: $this->ask('CheckOut', date('Y-m-d', strtotime('+9 days')));

        $data = [
            'PackageId' => $this->option('package-id') ?: '',
            'HotelId' => $hotelId,
            'RoomType' => $roomType,
            'CheckIn' => $checkIn,
            'CheckOut' => $checkOut,
            'RoomNum' => (int) $this->option('room-num'),
            'CustomerNumber' => (int) $this->option('customer-number'),
            'PaymentType' => (int) $this->option('payment-type'),
            'Extensions' => json_encode([]),
        ];

        $result = $client->validate($data);

        return $this->printResult('ValidateRQ', $result);
    }

    protected function runQuery(HengdianClient $client): int
    {
        $this->info('=== QueryStatusRQ 订单查询 ===');

        $otaOrderId = $this->option('ota-order-id') ?: $this->ask('OtaOrderId');
        if ($otaOrderId === null || $otaOrderId === '') {
            $this->error('缺少 --ota-order-id');

            return self::FAILURE;
        }

        $result = $client->query(['OtaOrderId' => $otaOrderId]);

        return $this->printResult('QueryStatusRQ', $result);
    }

    protected function runCancel(HengdianClient $client): int
    {
        $this->info('=== CancelRQ 取消订单 ===');

        $otaOrderId = $this->option('ota-order-id') ?: $this->ask('OtaOrderId');
        if ($otaOrderId === null || $otaOrderId === '') {
            $this->error('缺少 --ota-order-id');

            return self::FAILURE;
        }

        $reason = (string) $this->option('cancel-reason');
        $result = $client->cancel([
            'OtaOrderId' => $otaOrderId,
            'Reason' => $reason,
        ]);

        return $this->printResult('CancelRQ', $result);
    }

    protected function runSubscribe(HengdianClient $client): int
    {
        $this->info('=== SubscribeRoomStatusRQ 房态订阅 ===');

        $notifyUrl = $this->option('notify-url') ?: (string) config('services.hengdian.webhook_url', '');
        $unsubscribe = (bool) $this->option('unsubscribe');

        if (!$unsubscribe && $notifyUrl === '') {
            $notifyUrl = $this->ask('NotifyUrl（房态推送接收地址）');
        }

        $hotelId = $this->option('hotel-id') ?: $this->ask('HotelId', '001');
        $roomType = $this->option('room-type') ?: $this->ask('RoomType', '标准间');

        $data = [
            'NotifyUrl' => $unsubscribe ? '' : $notifyUrl,
            'IsUnsubscribe' => $unsubscribe ? '1' : '0',
            'Hotels' => [
                'Hotel' => [
                    [
                        'HotelId' => $hotelId,
                        'Rooms' => [
                            'RoomType' => [$roomType],
                        ],
                    ],
                ],
            ],
            'Extensions' => json_encode([]),
        ];

        $result = $client->subscribeRoomStatus($data);

        return $this->printResult('SubscribeRoomStatusRQ', $result);
    }

    protected function printResult(string $label, array $result): int
    {
        if ($this->option('json')) {
            $out = $result;
            if (isset($out['data']) && $out['data'] instanceof \SimpleXMLElement) {
                $out['data'] = json_decode(json_encode($out['data']), true);
            }
            $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $ok = $result['success'] ?? false;
            $this->line($label . '：' . ($ok ? '成功' : '失败'));
            $this->line('Message：' . ($result['message'] ?? ''));
            if (isset($result['data']) && $result['data'] instanceof \SimpleXMLElement) {
                $d = $result['data'];
                foreach (['ResultCode', 'OrderId', 'Status', 'OtaOrderId', 'InventoryPrice'] as $field) {
                    if (isset($d->{$field})) {
                        $this->line("{$field}：" . (string) $d->{$field});
                    }
                }
            }
        }

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 模拟横店房态推送 POST（文档 3.6 RoomStatus），用于本地验证 ResourceController。
     */
    protected function runPushSelf(): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('--push-self 仅允许 local / testing 环境');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');
        $url = $base . '/api/webhooks/test/resource-inventory-push';

        $payload = <<<'XML'
<RoomStatus>
<RoomQuotaMap>[{"hotelNo":"001","roomType":"标准间","roomQuota":[{"date":"2026-01-01","quota":10}]}]</RoomQuotaMap>
</RoomStatus>
XML;

        $this->info('POST ' . $url);

        $response = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/xml'])
            ->withBody($payload, 'application/xml')
            ->post($url);

        $this->line('HTTP ' . $response->status());
        $this->line($response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
