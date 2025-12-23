<?php

namespace Tests\Feature;

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
}

