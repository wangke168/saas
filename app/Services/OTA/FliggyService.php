<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform;
use App\Http\Client\FliggyClient;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;

class FliggyService
{
    protected ?FliggyClient $client = null;

    protected function getClient(): FliggyClient
    {
        if ($this->client === null) {
            $platform = OtaPlatformModel::where('code', OtaPlatform::FLIGGY->value)->first();
            $config = $platform?->config;
            
            if (!$config) {
                throw new \Exception('飞猪配置不存在');
            }
            
            $this->client = new FliggyClient($config);
        }
        
        return $this->client;
    }

    /**
     * 验证订单
     */
    public function validateOrder(array $orderData): array
    {
        return $this->getClient()->validateOrder($orderData);
    }

    /**
     * 取消订单
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->getClient()->cancelOrder($orderId);
    }
}

