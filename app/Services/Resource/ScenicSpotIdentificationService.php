<?php

namespace App\Services\Resource;

use App\Models\Hotel;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResourceConfig;
use App\Models\ScenicSpot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 景区识别服务
 * 用于在软件服务商回调时识别对应的景区和配置
 */
class ScenicSpotIdentificationService
{
    /**
     * 识别景区和配置
     * 
     * @param Request $request 请求对象
     * @param array $callbackData 回调数据
     * @param int|null $softwareProviderId 软件服务商ID（可选）
     * @return array|null 返回 ['scenic_spot' => ScenicSpot, 'config' => ResourceConfig, 'method' => string] 或 null
     */
    public static function identify(Request $request, array $callbackData, ?int $softwareProviderId = null): ?array
    {
        Log::info('景区识别服务：开始识别', [
            'callback_data_keys' => array_keys($callbackData),
            'request_path' => $request->path(),
            'software_provider_id' => $softwareProviderId,
        ]);

        // 优先级1：通过业务标识识别（最可靠）
        $result = self::identifyByBusinessData($callbackData, $softwareProviderId);
        if ($result) {
            Log::info('景区识别服务：通过业务标识识别成功', [
                'method' => $result['method'],
                'scenic_spot_id' => $result['scenic_spot']->id,
            ]);
            return $result;
        }

        // 优先级2：通过认证参数匹配
        if ($softwareProviderId) {
            $result = self::identifyByAuthParams($callbackData, $softwareProviderId);
            if ($result) {
                Log::info('景区识别服务：通过认证参数识别成功', [
                    'method' => $result['method'],
                    'scenic_spot_id' => $result['scenic_spot']->id,
                ]);
                return $result;
            }
        }

        // 优先级3：通过 URL 路径参数识别
        $result = self::identifyByUrlPath($request);
        if ($result) {
            Log::info('景区识别服务：通过 URL 路径识别成功', [
                'method' => $result['method'],
                'scenic_spot_id' => $result['scenic_spot']->id,
            ]);
            return $result;
        }

        Log::warning('景区识别服务：无法识别景区', [
            'callback_data_keys' => array_keys($callbackData),
            'request_path' => $request->path(),
            'software_provider_id' => $softwareProviderId,
        ]);

        return null;
    }

    /**
     * 通过业务标识识别景区（优先级最高）
     * 
     * @param array $callbackData 回调数据
     * @param int|null $softwareProviderId 软件服务商ID，用于过滤酒店（避免不同服务商的酒店external_code冲突）
     */
    protected static function identifyByBusinessData(array $callbackData, ?int $softwareProviderId = null): ?array
    {
        // 方法1：通过 hotelNo 识别
        if (isset($callbackData['hotelNo'])) {
            $result = self::identifyByHotelNo($callbackData['hotelNo'], $softwareProviderId);
            if ($result) {
                return $result;
            }
        }

        // 方法2：通过订单号识别
        if (isset($callbackData['orderNo']) || isset($callbackData['order_id'])) {
            $orderNo = $callbackData['orderNo'] ?? $callbackData['order_id'];
            $result = self::identifyByOrderNo($orderNo);
            if ($result) {
                return $result;
            }
        }

        // 方法3：通过产品ID识别
        if (isset($callbackData['productId']) || isset($callbackData['product_id'])) {
            $productId = $callbackData['productId'] ?? $callbackData['product_id'];
            $result = self::identifyByProductId($productId);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * 通过酒店编号识别景区
     * 
     * @param string $hotelNo 酒店编号
     * @param int|null $softwareProviderId 软件服务商ID，用于过滤酒店（避免不同服务商的酒店external_code冲突）
     */
    protected static function identifyByHotelNo(string $hotelNo, ?int $softwareProviderId = null): ?array
    {
        // 查找酒店（优先使用external_code，否则使用code）
        // 同时通过软件服务商过滤，避免不同服务商的酒店external_code冲突
        $hotelQuery = Hotel::where(function($query) use ($hotelNo) {
            $query->where('external_code', $hotelNo)
                  ->orWhere('code', $hotelNo);
        });
        
        // 如果提供了软件服务商ID，则通过景区关联的软件服务商过滤
        if ($softwareProviderId) {
            $hotelQuery->whereHas('scenicSpot', function($query) use ($softwareProviderId) {
                // 支持一对一关系（旧字段）和多对多关系
                $query->where(function($q) use ($softwareProviderId) {
                    $q->where('software_provider_id', $softwareProviderId)
                      ->orWhereHas('softwareProviders', function($subQuery) use ($softwareProviderId) {
                          $subQuery->where('software_providers.id', $softwareProviderId);
                      });
                });
            });
        }
        
        $hotel = $hotelQuery->with(['scenicSpot.resourceConfig'])->first();

        if ($hotel && $hotel->scenicSpot && $hotel->scenicSpot->resourceConfig) {
            return [
                'scenic_spot' => $hotel->scenicSpot,
                'config' => $hotel->scenicSpot->resourceConfig,
                'method' => 'hotelNo',
            ];
        }

        return null;
    }

    /**
     * 通过订单号识别景区
     */
    protected static function identifyByOrderNo(string $orderNo): ?array
    {
        $order = Order::where('resource_order_no', $orderNo)
            ->orWhere('ota_order_no', $orderNo)
            ->orWhere('order_no', $orderNo)
            ->orWhere('id', $orderNo)
            ->with(['hotel.scenicSpot.resourceConfig'])
            ->first();

        if ($order && $order->hotel && $order->hotel->scenicSpot && $order->hotel->scenicSpot->resourceConfig) {
            return [
                'scenic_spot' => $order->hotel->scenicSpot,
                'config' => $order->hotel->scenicSpot->resourceConfig,
                'method' => 'orderNo',
            ];
        }

        return null;
    }

    /**
     * 通过产品ID识别景区
     */
    protected static function identifyByProductId(string $productId): ?array
    {
        $product = Product::where('external_code', $productId)
            ->orWhere('code', $productId)
            ->orWhere('id', $productId)
            ->with(['scenicSpot.resourceConfig'])
            ->first();

        if ($product && $product->scenicSpot && $product->scenicSpot->resourceConfig) {
            return [
                'scenic_spot' => $product->scenicSpot,
                'config' => $product->scenicSpot->resourceConfig,
                'method' => 'productId',
            ];
        }

        return null;
    }

    /**
     * 通过认证参数匹配识别景区
     */
    protected static function identifyByAuthParams(array $callbackData, int $softwareProviderId): ?array
    {
        // 方法1：通过 username 匹配
        if (isset($callbackData['username'])) {
            $config = ResourceConfig::where('software_provider_id', $softwareProviderId)
                ->where('username', $callbackData['username'])
                ->with('scenicSpot')
                ->first();

            if ($config && $config->scenicSpot) {
                return [
                    'scenic_spot' => $config->scenicSpot,
                    'config' => $config,
                    'method' => 'username',
                ];
            }
        }

        // 方法2：通过 appkey 或 app_id 匹配
        if (isset($callbackData['appkey']) || isset($callbackData['app_id'])) {
            $appkey = $callbackData['appkey'] ?? $callbackData['app_id'];
            $config = ResourceConfig::where('software_provider_id', $softwareProviderId)
                ->where(function($query) use ($appkey) {
                    $query->whereJsonContains('extra_config->auth->appkey', $appkey)
                          ->orWhereJsonContains('extra_config->auth->app_id', $appkey);
                })
                ->with('scenicSpot')
                ->first();

            if ($config && $config->scenicSpot) {
                return [
                    'scenic_spot' => $config->scenicSpot,
                    'config' => $config,
                    'method' => 'appkey',
                ];
            }
        }

        // 方法3：通过 token 或 access_token 匹配
        if (isset($callbackData['token']) || isset($callbackData['access_token'])) {
            $token = $callbackData['token'] ?? $callbackData['access_token'];
            $config = ResourceConfig::where('software_provider_id', $softwareProviderId)
                ->where(function($query) use ($token) {
                    $query->whereJsonContains('extra_config->auth->token', $token)
                          ->orWhereJsonContains('extra_config->auth->access_token', $token);
                })
                ->with('scenicSpot')
                ->first();

            if ($config && $config->scenicSpot) {
                return [
                    'scenic_spot' => $config->scenicSpot,
                    'config' => $config,
                    'method' => 'token',
                ];
            }
        }

        return null;
    }

    /**
     * 通过 URL 路径参数识别景区
     */
    protected static function identifyByUrlPath(Request $request): ?array
    {
        $scenicSpotCode = $request->route('scenicSpot');
        if ($scenicSpotCode) {
            $scenicSpot = ScenicSpot::where('code', $scenicSpotCode)
                ->with('resourceConfig')
                ->first();

            if ($scenicSpot && $scenicSpot->resourceConfig) {
                return [
                    'scenic_spot' => $scenicSpot,
                    'config' => $scenicSpot->resourceConfig,
                    'method' => 'url_path',
                ];
            }
        }

        return null;
    }
}

