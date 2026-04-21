<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrderVerificationJob;
use App\Models\Order;
use App\Models\OtaPlatform;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 测试携程订单回调
     */
    public function test_ctrip_order_webhook(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/webhooks/ctrip', [
            'orderId' => 'CTRIP123456',
            'orderStatus' => 'paid',
            'productId' => 'PROD123',
            'quantity' => 1,
            'totalPrice' => 1000.00,
            'contactName' => '测试用户',
            'contactMobile' => '13800138000',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'msg' => 'success',
            ]);
    }

    /**
     * 测试飞猪产品变更通知
     */
    public function test_fliggy_product_change_webhook(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/webhooks/fliggy/product-change', [
            'productId' => 'FLIGGY123',
            'changeType' => 'price',
            'newPrice' => 1200.00,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'result' => 'success',
            ]);
    }

    /**
     * 测试飞猪订单状态通知
     */
    public function test_fliggy_order_status_webhook(): void
    {
        $otaPlatform = OtaPlatform::factory()->create(['code' => 'fliggy']);
        $order = Order::factory()->create([
            'ota_platform_id' => $otaPlatform->id,
            'ota_order_no' => 'FLIGGY123456',
            'status' => OrderStatus::PAID_PENDING->value,
        ]);

        Queue::fake();

        $response = $this->postJson('/api/webhooks/fliggy/order-status', [
            'orderId' => 'FLIGGY123456',
            'status' => 'cancelled',
            'cancelReason' => '用户取消',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'result' => 'success',
            ]);

        $order->refresh();
        // 验证订单状态已更新
        $this->assertNotEquals(OrderStatus::PAID_PENDING->value, $order->status);
    }

    /**
     * 测试无效的Webhook数据
     */
    public function test_invalid_webhook_data_returns_error(): void
    {
        $response = $this->postJson('/api/webhooks/ctrip', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['orderId']);
    }

    /**
     * 测试横店订单使用状态定时推送（3.7 JSON）会派发核销处理任务
     */
    public function test_hengdian_order_verification_json_webhook_dispatches_jobs(): void
    {
        Queue::fake();

        $order = Order::create([
            'order_no' => 'ORD-HD-001',
            'ota_order_no' => 'OTA-HD-001',
            'status' => OrderStatus::CONFIRMED->value,
            'product_id' => 1,
            'hotel_id' => 1,
            'check_in_date' => now(),
            'check_out_date' => now()->addDay(),
            'contact_name' => 'Test',
            'contact_phone' => '13800138000',
            'total_amount' => 100,
        ]);

        $response = $this->postJson('/api/webhooks/resource/hengdian/order-verification', [
            'orders' => [
                [
                    'otaOrderNo' => 'OTA-HD-001',
                    'orderNo' => 'HD-ORDER-001',
                    'useDate' => '2026-04-20 03:00:00',
                ],
                [
                    'otaOrderNo' => 'OTA-NOT-EXISTS',
                    'orderNo' => 'HD-ORDER-999',
                    'useDate' => '2026-04-20 03:00:00',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'result' => 'TRUE',
                'received' => 2,
                'accepted' => 1,
            ]);

        Queue::assertPushed(ProcessOrderVerificationJob::class, function (ProcessOrderVerificationJob $job) use ($order) {
            return $job->orderId === $order->id
                && $job->source === 'webhook'
                && ($job->verificationData['status'] ?? null) === OrderStatus::VERIFIED->value;
        });
    }
}

