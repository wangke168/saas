<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PkgOrderStatus;
use App\Jobs\PushExternalOrderJob;
use App\Models\Order;
use App\Models\OrderExternalPushLog;
use App\Models\Pkg\PkgOrder;
use App\Services\ExternalOrder\ExternalOrderPushConfigService;
use App\Services\ExternalOrder\ExternalOrderPushDispatcher;
use App\Services\ExternalOrder\ExternalOrderPushService;
use App\Services\Presale\PresaleOtaConsumeService;
use App\Support\ExternalOrder\ExternalOrderRoute;
use Illuminate\Console\Command;
use RuntimeException;

class TestExternalOrderPush extends Command
{
    protected $signature = 'test:external-order-push
                            {--order= : 酒店订单 ID}
                            {--order-no= : 酒店订单号 order_no}
                            {--pkg-order= : 打包订单 ID}
                            {--pkg-order-no= : 打包订单号 order_no}
                            {--action=create : 推送动作 create|status|paid}
                            {--status=20 : status 动作的 routeOrderStatus（20|30|50）}
                            {--force : 忽略幂等，强制重推}
                            {--queue : 通过队列异步推送（默认同步直调）}
                            {--dry-run : 仅预览 payload，不发起 HTTP 请求}
                            {--json : 以 JSON 格式输出结果}';

    protected $description = '测试向 tripfastpass 推送订单（createOrder / updateOrderStatus）';

    public function __construct(
        private readonly ExternalOrderPushService $pushService,
        private readonly ExternalOrderPushDispatcher $dispatcher,
        private readonly ExternalOrderPushConfigService $configService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('========================================');
        $this->info('第三方订单推送测试');
        $this->info('========================================');
        $this->newLine();

        try {
            [$orderType, $orderId, $orderNo] = $this->resolveTarget();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $action = strtolower((string) $this->option('action'));
        if (! in_array($action, ['create', 'status', 'paid'], true)) {
            $this->error('--action 仅支持 create、status、paid');

            return self::FAILURE;
        }

        $this->displayConfig();
        $this->displayOrderSummary($orderType, $orderId, $orderNo);

        if ($action === 'paid') {
            return $this->runPaidFlow($orderType, $orderId);
        }

        [$pushType, $routeStatus] = $this->resolvePushParams($action);

        if ($this->option('dry-run')) {
            return $this->runDryRun($orderType, $orderId, $pushType, $routeStatus);
        }

        return $this->runPush($orderType, $orderId, $pushType, $routeStatus);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function resolveTarget(): array
    {
        $orderId = $this->option('order');
        $orderNo = $this->option('order-no');
        $pkgOrderId = $this->option('pkg-order');
        $pkgOrderNo = $this->option('pkg-order-no');

        $specified = array_filter([
            $orderId,
            $orderNo,
            $pkgOrderId,
            $pkgOrderNo,
        ], static fn ($value) => $value !== null && $value !== '');

        if (count($specified) === 0) {
            throw new RuntimeException('请指定 --order、--order-no、--pkg-order 或 --pkg-order-no 之一');
        }

        if (count($specified) > 1) {
            throw new RuntimeException('请只指定一种订单定位参数');
        }

        if ($orderId) {
            $order = Order::query()->find((int) $orderId);
            if ($order === null) {
                throw new RuntimeException("酒店订单不存在: {$orderId}");
            }

            return [OrderExternalPushLog::ORDER_TYPE_ORDER, $order->id, $order->order_no];
        }

        if ($orderNo) {
            $order = Order::query()->where('order_no', (string) $orderNo)->first();
            if ($order === null) {
                throw new RuntimeException("酒店订单不存在: {$orderNo}");
            }

            return [OrderExternalPushLog::ORDER_TYPE_ORDER, $order->id, $order->order_no];
        }

        if ($pkgOrderId) {
            $pkgOrder = PkgOrder::query()->find((int) $pkgOrderId);
            if ($pkgOrder === null) {
                throw new RuntimeException("打包订单不存在: {$pkgOrderId}");
            }

            return [OrderExternalPushLog::ORDER_TYPE_PKG_ORDER, $pkgOrder->id, $pkgOrder->order_no];
        }

        $pkgOrder = PkgOrder::query()->where('order_no', (string) $pkgOrderNo)->first();
        if ($pkgOrder === null) {
            throw new RuntimeException("打包订单不存在: {$pkgOrderNo}");
        }

        return [OrderExternalPushLog::ORDER_TYPE_PKG_ORDER, $pkgOrder->id, $pkgOrder->order_no];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolvePushParams(string $action): array
    {
        if ($action === 'create') {
            return [OrderExternalPushLog::PUSH_TYPE_CREATE, ExternalOrderRoute::STATUS_PENDING];
        }

        $routeStatus = (int) $this->option('status');
        if (! in_array($routeStatus, [
            ExternalOrderRoute::STATUS_CONFIRMED,
            ExternalOrderRoute::STATUS_VERIFIED,
            ExternalOrderRoute::STATUS_CANCELLED,
        ], true)) {
            throw new RuntimeException('--status 仅支持 20、30、50');
        }

        return [OrderExternalPushLog::PUSH_TYPE_STATUS_UPDATE, $routeStatus];
    }

    private function runPaidFlow(string $orderType, int $orderId): int
    {
        $this->info('[动作] paid（模拟支付成功：create + 若已确认则补推 20）');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('paid 动作不支持 --dry-run，将分别预览 create 与可能的 status=20');

            $createPreview = $this->runDryRun(
                $orderType,
                $orderId,
                OrderExternalPushLog::PUSH_TYPE_CREATE,
                ExternalOrderRoute::STATUS_PENDING,
                false,
            );

            if ($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER) {
                $order = Order::query()->findOrFail($orderId);
                if ($order->status === OrderStatus::CONFIRMED) {
                    $this->newLine();
                    $this->runDryRun(
                        $orderType,
                        $orderId,
                        OrderExternalPushLog::PUSH_TYPE_STATUS_UPDATE,
                        ExternalOrderRoute::STATUS_CONFIRMED,
                        false,
                    );
                }
            } elseif ($orderType === OrderExternalPushLog::ORDER_TYPE_PKG_ORDER) {
                $pkgOrder = PkgOrder::query()->findOrFail($orderId);
                if ($pkgOrder->status === PkgOrderStatus::CONFIRMED) {
                    $this->newLine();
                    $this->runDryRun(
                        $orderType,
                        $orderId,
                        OrderExternalPushLog::PUSH_TYPE_STATUS_UPDATE,
                        ExternalOrderRoute::STATUS_CONFIRMED,
                        false,
                    );
                }
            }

            return $createPreview;
        }

        if ($this->option('queue')) {
            if ($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER) {
                $order = Order::query()->findOrFail($orderId);
                if (PresaleOtaConsumeService::isPresaleParentOrder($order)) {
                    $this->warn('预售父单不会推送');

                    return self::FAILURE;
                }
                $this->dispatcher->dispatchOrderPaid($order);
            } else {
                $pkgOrder = PkgOrder::query()->findOrFail($orderId);
                $this->dispatcher->dispatchPkgOrderPaid($pkgOrder);
            }

            $this->info('已派发到队列 external-order-push，请确保 queue worker 正在运行');

            return self::SUCCESS;
        }

        $exitCode = $this->runPush(
            $orderType,
            $orderId,
            OrderExternalPushLog::PUSH_TYPE_CREATE,
            ExternalOrderRoute::STATUS_PENDING,
        );

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $shouldPushConfirmed = false;
        if ($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER) {
            $order = Order::query()->findOrFail($orderId);
            $shouldPushConfirmed = $order->status === OrderStatus::CONFIRMED;
        } else {
            $pkgOrder = PkgOrder::query()->findOrFail($orderId);
            $shouldPushConfirmed = $pkgOrder->status === PkgOrderStatus::CONFIRMED;
        }

        if ($shouldPushConfirmed) {
            $this->newLine();
            $this->info('订单当前为已确认，继续推送 status=20 ...');
            $this->newLine();

            return $this->runPush(
                $orderType,
                $orderId,
                OrderExternalPushLog::PUSH_TYPE_STATUS_UPDATE,
                ExternalOrderRoute::STATUS_CONFIRMED,
            );
        }

        return self::SUCCESS;
    }

    private function runDryRun(
        string $orderType,
        int $orderId,
        string $pushType,
        int $routeStatus,
        bool $showHeader = true,
    ): int {
        if ($showHeader) {
            $this->info('[模式] dry-run（仅预览，不发起 HTTP 请求）');
            $this->newLine();
        }

        try {
            $preview = $this->pushService->preview($orderType, $orderId, $pushType, $routeStatus);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($preview === null) {
            $this->warn('无法构建 payload（可能不是携程/美团订单）');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) config('services.external_order_push.api_url'), '/');
        $url = $baseUrl.$preview['endpoint'];

        if ($this->option('json')) {
            $this->line(json_encode([
                'url' => $url,
                'preview' => $preview,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line("URL: {$url}");
        $this->line('Payload:');
        $this->line(json_encode($preview['payload'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function runPush(string $orderType, int $orderId, string $pushType, int $routeStatus): int
    {
        $force = (bool) $this->option('force');
        $actionLabel = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE ? 'createOrder' : 'updateOrderStatus';

        $this->info("[动作] {$actionLabel} (routeOrderStatus={$routeStatus})");
        if ($force) {
            $this->warn('已启用 --force，将忽略幂等检查');
        }
        $this->newLine();

        if ($this->option('queue')) {
            PushExternalOrderJob::dispatch($orderType, $orderId, $pushType, $routeStatus);
            $this->info('已派发到队列 external-order-push');

            return self::SUCCESS;
        }

        try {
            $log = $this->pushService->push(
                $orderType,
                $orderId,
                $pushType,
                $routeStatus,
                1,
                $force,
            );
        } catch (RuntimeException $e) {
            $this->error('推送失败: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($log === null) {
            $this->warn('推送被跳过（景区未开启、非携程/美团、或已成功推送过；可加 --force 重试）');

            return self::FAILURE;
        }

        $this->displayPushResult($log);

        return $log->status === OrderExternalPushLog::STATUS_SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function displayConfig(): void
    {
        $apiUrl = (string) config('services.external_order_push.api_url');
        $this->info('[配置]');
        $this->line('  API Base URL: '.($apiUrl !== '' ? $apiUrl : '(未配置)'));
        $this->newLine();
    }

    private function displayOrderSummary(string $orderType, int $orderId, string $orderNo): void
    {
        $this->info('[订单信息]');
        $this->line('  类型: '.($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER ? '酒店订单' : '打包订单'));
        $this->line("  ID: {$orderId}");
        $this->line("  订单号: {$orderNo}");

        if ($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER) {
            $order = Order::with(['otaPlatform', 'product', 'hotel'])->find($orderId);
            if ($order) {
                $this->line('  OTA订单号: '.($order->ota_order_no ?: '(空)'));
                $this->line('  平台: '.($order->otaPlatform?->name ?? $order->otaPlatform?->code?->value ?? '(未知)'));
                $this->line('  状态: '.$order->status->value);
                $scenicSpotId = $this->dispatcher->resolveScenicSpotIdForOrder($order);
                $this->line('  景区ID: '.($scenicSpotId ?? '(未知)'));
                $this->line('  推送开关: '.($this->configService->isEnabled($scenicSpotId) ? '已开启' : '未开启'));
                if (PresaleOtaConsumeService::isPresaleParentOrder($order)) {
                    $this->warn('  注意：这是预售父单，线上逻辑不会推送');
                }
            }
        } else {
            $pkgOrder = PkgOrder::with(['otaPlatform', 'hotel'])->find($orderId);
            if ($pkgOrder) {
                $this->line('  OTA订单号: '.($pkgOrder->ota_order_no ?: '(空)'));
                $this->line('  平台: '.($pkgOrder->otaPlatform?->name ?? $pkgOrder->otaPlatform?->code?->value ?? '(未知)'));
                $this->line('  状态: '.$pkgOrder->status->value);
                $scenicSpotId = $this->dispatcher->resolveScenicSpotIdForPkgOrder($pkgOrder);
                $this->line('  景区ID: '.($scenicSpotId ?? '(未知)'));
                $this->line('  推送开关: '.($this->configService->isEnabled($scenicSpotId) ? '已开启' : '未开启'));
            }
        }

        $this->newLine();
    }

    private function displayPushResult(OrderExternalPushLog $log): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'log_id' => $log->id,
                'status' => $log->status,
                'http_status' => $log->http_status,
                'endpoint' => $log->endpoint,
                'request_payload' => $log->request_payload,
                'response_body' => $log->response_body,
                'error_message' => $log->error_message,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }

        $this->info('[推送结果]');
        $this->line("  日志ID: {$log->id}");
        $this->line("  状态: {$log->status}");
        $this->line('  HTTP: '.($log->http_status ?? '(无)'));

        if ($log->response_body) {
            $this->line('  响应: '.json_encode($log->response_body, JSON_UNESCAPED_UNICODE));
        }

        if ($log->error_message) {
            $this->error('  错误: '.$log->error_message);
        }
    }
}
