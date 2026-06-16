<?php

namespace App\Services\ExternalOrder;

use App\Models\Order;
use App\Models\OrderExternalPushLog;
use App\Models\Pkg\PkgOrder;
use App\Support\ExternalOrder\ExternalOrderRoute;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExternalOrderPushService
{
    public function __construct(
        private readonly ExternalOrderPayloadBuilder $payloadBuilder,
        private readonly ExternalOrderPushConfigService $configService,
        private readonly ExternalOrderPushDispatcher $dispatcher,
    ) {}

    public function push(
        string $orderType,
        int $orderId,
        string $pushType,
        int $routeOrderStatus,
        int $attempt = 1,
        bool $force = false,
    ): ?OrderExternalPushLog {
        if ($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER) {
            $order = Order::query()->find($orderId);
            if ($order === null) {
                throw new RuntimeException("订单不存在: {$orderId}");
            }

            $scenicSpotId = $this->dispatcher->resolveScenicSpotIdForOrder($order);
            if (! $this->configService->isEnabled($scenicSpotId)) {
                $this->dispatcher->logSkipped('景区未开启订单推送，跳过', [
                    'order_id' => $orderId,
                    'scenic_spot_id' => $scenicSpotId,
                ]);

                return null;
            }

            $payload = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE
                ? $this->payloadBuilder->buildCreatePayload($order)
                : $this->payloadBuilder->buildStatusUpdatePayload($order, $routeOrderStatus);
            $orderNo = $order->order_no;
        } elseif ($orderType === OrderExternalPushLog::ORDER_TYPE_PKG_ORDER) {
            $pkgOrder = PkgOrder::query()->find($orderId);
            if ($pkgOrder === null) {
                throw new RuntimeException("打包订单不存在: {$orderId}");
            }

            $scenicSpotId = $this->dispatcher->resolveScenicSpotIdForPkgOrder($pkgOrder);
            if (! $this->configService->isEnabled($scenicSpotId)) {
                $this->dispatcher->logSkipped('景区未开启订单推送，跳过', [
                    'pkg_order_id' => $orderId,
                    'scenic_spot_id' => $scenicSpotId,
                ]);

                return null;
            }

            $payload = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE
                ? $this->payloadBuilder->buildCreatePayloadForPkgOrder($pkgOrder)
                : $this->payloadBuilder->buildStatusUpdatePayloadForPkgOrder($pkgOrder, $routeOrderStatus);
            $orderNo = $pkgOrder->order_no;
        } else {
            throw new RuntimeException("不支持的订单类型: {$orderType}");
        }

        if ($payload === null) {
            $this->dispatcher->logSkipped('非携程/美团订单或缺少平台信息，跳过', [
                'order_type' => $orderType,
                'order_id' => $orderId,
            ]);

            return null;
        }

        if (! $force && $this->hasSuccessfulPush($orderType, $orderId, $pushType, $routeOrderStatus)) {
            $this->dispatcher->logSkipped('已成功推送过相同事件，跳过', [
                'order_type' => $orderType,
                'order_id' => $orderId,
                'push_type' => $pushType,
                'route_order_status' => $routeOrderStatus,
            ]);

            return null;
        }

        $endpoint = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE
            ? '/api/hd/createOrder'
            : '/api/hd/updateOrderStatus';

        $log = OrderExternalPushLog::query()->create([
            'order_type' => $orderType,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'scenic_spot_id' => $scenicSpotId ?? null,
            'push_type' => $pushType,
            'route_order_status' => $routeOrderStatus,
            'endpoint' => $endpoint,
            'request_payload' => $payload,
            'status' => OrderExternalPushLog::STATUS_PENDING,
            'attempt' => $attempt,
        ]);

        $baseUrl = rtrim((string) config('services.external_order_push.api_url'), '/');
        if ($baseUrl === '') {
            $this->markLogFailed($log, null, null, '未配置 EXTERNAL_ORDER_PUSH_API_URL');

            throw new RuntimeException('未配置 EXTERNAL_ORDER_PUSH_API_URL');
        }

        $url = $baseUrl.$endpoint;

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            $responseBody = $response->json();
            if (! is_array($responseBody)) {
                $responseBody = ['raw' => $response->body()];
            }

            $responseCode = (string) ($responseBody['code'] ?? '');

            if ($response->successful() && ExternalOrderRoute::isSuccessResponseCode($responseCode)) {
                $log->update([
                    'http_status' => $response->status(),
                    'response_body' => $responseBody,
                    'status' => OrderExternalPushLog::STATUS_SUCCESS,
                    'error_message' => null,
                ]);

                Log::info('ExternalOrderPushService: 推送成功', [
                    'log_id' => $log->id,
                    'order_type' => $orderType,
                    'order_id' => $orderId,
                    'push_type' => $pushType,
                    'route_order_status' => $routeOrderStatus,
                ]);

                return $log->fresh();
            }

            $errorMessage = (string) ($responseBody['msg'] ?? $response->body());
            $this->markLogFailed($log, $response->status(), $responseBody, $errorMessage);

            throw new RuntimeException('第三方订单推送失败: '.$errorMessage);
        } catch (ConnectionException $e) {
            $this->markLogFailed($log, null, null, $e->getMessage());

            throw new RuntimeException('第三方订单推送连接失败: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * 预览推送 payload（不发起 HTTP 请求）
     *
     * @return array{order_type: string, order_id: int, order_no: string, scenic_spot_id: ?int, push_type: string, route_order_status: int, endpoint: string, payload: array<string, mixed>}|null
     */
    public function preview(
        string $orderType,
        int $orderId,
        string $pushType,
        int $routeOrderStatus,
    ): ?array {
        if ($orderType === OrderExternalPushLog::ORDER_TYPE_ORDER) {
            $order = Order::query()->find($orderId);
            if ($order === null) {
                throw new RuntimeException("订单不存在: {$orderId}");
            }

            $scenicSpotId = $this->dispatcher->resolveScenicSpotIdForOrder($order);
            $payload = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE
                ? $this->payloadBuilder->buildCreatePayload($order)
                : $this->payloadBuilder->buildStatusUpdatePayload($order, $routeOrderStatus);
            $orderNo = $order->order_no;
        } elseif ($orderType === OrderExternalPushLog::ORDER_TYPE_PKG_ORDER) {
            $pkgOrder = PkgOrder::query()->find($orderId);
            if ($pkgOrder === null) {
                throw new RuntimeException("打包订单不存在: {$orderId}");
            }

            $scenicSpotId = $this->dispatcher->resolveScenicSpotIdForPkgOrder($pkgOrder);
            $payload = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE
                ? $this->payloadBuilder->buildCreatePayloadForPkgOrder($pkgOrder)
                : $this->payloadBuilder->buildStatusUpdatePayloadForPkgOrder($pkgOrder, $routeOrderStatus);
            $orderNo = $pkgOrder->order_no;
        } else {
            throw new RuntimeException("不支持的订单类型: {$orderType}");
        }

        if ($payload === null) {
            return null;
        }

        $endpoint = $pushType === OrderExternalPushLog::PUSH_TYPE_CREATE
            ? '/api/hd/createOrder'
            : '/api/hd/updateOrderStatus';

        return [
            'order_type' => $orderType,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'scenic_spot_id' => $scenicSpotId ?? null,
            'push_type' => $pushType,
            'route_order_status' => $routeOrderStatus,
            'endpoint' => $endpoint,
            'payload' => $payload,
        ];
    }

    private function hasSuccessfulPush(
        string $orderType,
        int $orderId,
        string $pushType,
        int $routeOrderStatus,
    ): bool {
        return OrderExternalPushLog::query()
            ->where('order_type', $orderType)
            ->where('order_id', $orderId)
            ->where('push_type', $pushType)
            ->where('route_order_status', $routeOrderStatus)
            ->where('status', OrderExternalPushLog::STATUS_SUCCESS)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function markLogFailed(
        OrderExternalPushLog $log,
        ?int $httpStatus,
        ?array $responseBody,
        string $errorMessage,
    ): void {
        $log->update([
            'http_status' => $httpStatus,
            'response_body' => $responseBody,
            'status' => OrderExternalPushLog::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);

        Log::warning('ExternalOrderPushService: 推送失败', [
            'log_id' => $log->id,
            'order_type' => $log->order_type,
            'order_id' => $log->order_id,
            'push_type' => $log->push_type,
            'route_order_status' => $log->route_order_status,
            'error' => $errorMessage,
        ]);
    }
}
