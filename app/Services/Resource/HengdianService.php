<?php

namespace App\Services\Resource;

use App\Http\Client\HengdianClient;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Models\ResourceProvider;
use Illuminate\Support\Facades\Log;

class HengdianService
{
    protected ?HengdianClient $client = null;

    protected function getClient(): HengdianClient
    {
        if ($this->client === null) {
            $provider = ResourceProvider::where('api_type', 'hengdian')->first();
            $config = $provider?->config;
            
            if (!$config) {
                throw new \Exception('横店配置不存在');
            }
            
            $this->client = new HengdianClient($config);
        }
        
        return $this->client;
    }

    /**
     * 验证价格和库存
     */
    public function validate(array $data): array
    {
        return $this->getClient()->validate($data);
    }

    /**
     * 下单预订
     */
    public function book(Order $order): array
    {
        $data = [
            'OtaOrderId' => $order->ota_order_no,
            'PackageId' => $order->product->code ?? '',
            'HotelId' => $order->hotel->code ?? '',
            'RoomType' => $order->roomType->name ?? '',
            'CheckIn' => $order->check_in_date->format('Y-m-d'),
            'CheckOut' => $order->check_out_date->format('Y-m-d'),
            'RoomNum' => $order->room_count,
            'CustomerNumber' => $order->guest_count,
            'PaymentType' => 5, // 现付
            'Extensions' => json_encode([]),
        ];

        return $this->getClient()->book($data);
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $otaOrderId): array
    {
        return $this->getClient()->query([
            'OtaOrderId' => $otaOrderId,
        ]);
    }

    /**
     * 取消订单
     */
    public function cancelOrder(string $otaOrderId): array
    {
        return $this->getClient()->cancel([
            'OtaOrderId' => $otaOrderId,
        ]);
    }
}

