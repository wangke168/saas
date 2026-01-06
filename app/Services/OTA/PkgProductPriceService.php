<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Models\Pkg\PkgProduct;
use App\Models\Pkg\PkgProductDailyPrice;
use App\Models\Res\ResHotelDailyStock;
use App\Services\Pkg\PkgProductCodeService;
use App\Http\Client\CtripClient;
use App\Http\Client\MeituanClient;
use Illuminate\Support\Facades\Log;

/**
 * 打包产品价格推送服务
 * 负责将打包产品的价格推送到各个OTA平台
 */
class PkgProductPriceService
{
    protected ?CtripClient $ctripClient = null;
    protected ?MeituanClient $meituanClient = null;

    /**
     * 创建携程客户端
     */
    protected function getCtripClient(): CtripClient
    {
        if ($this->ctripClient === null) {
            $config = $this->createCtripConfig();
            if (!$config) {
                throw new \Exception('携程配置不存在，请检查 .env 文件中的环境变量配置');
            }
            $this->ctripClient = new CtripClient($config);
        }
        return $this->ctripClient;
    }

    /**
     * 创建美团客户端
     */
    protected function getMeituanClient(): MeituanClient
    {
        if ($this->meituanClient === null) {
            $config = $this->createMeituanConfig();
            if (!$config) {
                throw new \Exception('美团配置不存在，请检查数据库配置或环境变量');
            }
            $this->meituanClient = new MeituanClient($config);
        }
        return $this->meituanClient;
    }

    /**
     * 从环境变量创建携程配置对象
     */
    protected function createCtripConfig(): ?OtaConfig
    {
        if (!env('CTRIP_ACCOUNT_ID') || !env('CTRIP_SECRET_KEY')) {
            return null;
        }

        $config = new OtaConfig();
        $config->account = env('CTRIP_ACCOUNT_ID');
        $config->secret_key = env('CTRIP_SECRET_KEY');
        $config->aes_key = env('CTRIP_ENCRYPT_KEY', '');
        $config->aes_iv = env('CTRIP_ENCRYPT_IV', '');
        $config->api_url = env('CTRIP_PRICE_API_URL', 'https://ttdopen.ctrip.com/api/product/price.do');
        $config->callback_url = env('CTRIP_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 从环境变量创建美团配置对象
     */
    protected function createMeituanConfig(): ?OtaConfig
    {
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            // 尝试从数据库读取
            $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
            return $platform?->config;
        }

        $config = new OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID');
        $config->secret_key = env('MEITUAN_APP_KEY');
        $config->aes_key = env('MEITUAN_APP_SECRET');
        $config->aes_iv = env('MEITUAN_AES_KEY', '');
        $config->api_url = env('MEITUAN_API_URL', 'https://openapi.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 推送打包产品价格到携程
     * 
     * @param PkgProduct $pkgProduct 打包产品
     * @param int $hotelId 酒店ID
     * @param int $roomTypeId 房型ID
     * @param array|null $dates 指定日期数组，如果为null则推送未来60天
     * @return array
     */
    public function syncToCtrip(
        PkgProduct $pkgProduct,
        int $hotelId,
        int $roomTypeId,
        ?array $dates = null
    ): array {
        try {
            // 生成复合编码
            $compositeCode = PkgProductCodeService::generate(
                $pkgProduct->id,
                $hotelId,
                $roomTypeId
            );

            // 获取价格数据
            $query = PkgProductDailyPrice::where('pkg_product_id', $pkgProduct->id)
                ->where('hotel_id', $hotelId)
                ->where('room_type_id', $roomTypeId);

            if ($dates !== null) {
                $query->whereIn('biz_date', $dates);
            } else {
                // 默认推送未来60天
                $startDate = now()->format('Y-m-d');
                $endDate = now()->addDays(60)->format('Y-m-d');
                $query->whereBetween('biz_date', [$startDate, $endDate]);
            }

            $priceList = $query->orderBy('biz_date')->get();

            if ($priceList->isEmpty()) {
                return [
                    'success' => false,
                    'message' => '没有价格数据',
                ];
            }

            // 构建请求体
            $bodyData = [
                'sequenceId' => date('Ymd') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
                'dateType' => 'DATE_REQUIRED',
                'supplierOptionId' => $compositeCode, // 使用复合编码作为产品标识
                'prices' => [],
            ];

            // 转换价格数据
            foreach ($priceList as $price) {
                $bodyData['prices'][] = [
                    'salePrice' => floatval($price->sale_price) / 100, // 转换为元
                    'costPrice' => floatval($price->cost_price) / 100, // 转换为元
                    'date' => $price->biz_date->format('Y-m-d'),
                ];
            }

            Log::info('准备推送打包产品价格到携程', [
                'pkg_product_id' => $pkgProduct->id,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'composite_code' => $compositeCode,
                'price_count' => count($bodyData['prices']),
            ]);

            $client = $this->getCtripClient();
            $result = $client->syncPrice($bodyData);

            if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
                return [
                    'success' => true,
                    'message' => '推送成功',
                    'data' => $result,
                ];
            }

            return [
                'success' => false,
                'message' => $result['header']['resultMessage'] ?? '推送失败',
                'data' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('推送打包产品价格到携程失败', [
                'pkg_product_id' => $pkgProduct->id,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '推送异常：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 推送打包产品价格到美团
     * 
     * @param PkgProduct $pkgProduct 打包产品
     * @param int $hotelId 酒店ID
     * @param int $roomTypeId 房型ID
     * @param string|null $startDate 开始日期（Y-m-d格式），如果为null则使用今天
     * @param string|null $endDate 结束日期（Y-m-d格式），如果为null则使用60天后
     * @return array
     */
    public function syncToMeituan(
        PkgProduct $pkgProduct,
        int $hotelId,
        int $roomTypeId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        try {
            // 生成复合编码
            $compositeCode = PkgProductCodeService::generate(
                $pkgProduct->id,
                $hotelId,
                $roomTypeId
            );

            // 设置默认日期范围（未来60天）
            if ($startDate === null) {
                $startDate = now()->format('Y-m-d');
            }
            if ($endDate === null) {
                $endDate = now()->addDays(60)->format('Y-m-d');
            }

            // 获取价格数据
            $priceList = PkgProductDailyPrice::where('pkg_product_id', $pkgProduct->id)
                ->where('hotel_id', $hotelId)
                ->where('room_type_id', $roomTypeId)
                ->whereBetween('biz_date', [$startDate, $endDate])
                ->orderBy('biz_date')
                ->get();

            if ($priceList->isEmpty()) {
                return [
                    'success' => false,
                    'message' => '没有价格数据',
                ];
            }

            // 加载关联数据
            $hotel = \App\Models\Res\ResHotel::find($hotelId);
            $roomType = \App\Models\Res\ResRoomType::find($roomTypeId);

            if (!$hotel || !$roomType) {
                return [
                    'success' => false,
                    'message' => '酒店或房型不存在',
                ];
            }

            // 构建请求数据（美团使用多层价格日历格式）
            $client = $this->getMeituanClient();
            $partnerId = $client->getPartnerId();
            
            $body = [];
            foreach ($priceList as $price) {
                $date = $price->biz_date->format('Y-m-d');
                
                // 获取库存（从 res_hotel_daily_stock 表）
                $stock = 0;
                $inventory = ResHotelDailyStock::where('hotel_id', $hotelId)
                    ->where('room_type_id', $roomTypeId)
                    ->where('biz_date', $date)
                    ->first();
                
                if ($inventory) {
                    // stock_available 是计算字段（stock_total - stock_sold）
                    $stock = $inventory->stock_available ?? 0;
                }

                // 生成 partnerPrimaryKey（SKU唯一标识）
                $partnerPrimaryKey = md5("{$hotelId}|{$roomTypeId}|{$date}");

                $body[] = [
                    'partnerPrimaryKey' => $partnerPrimaryKey,
                    'skuInfo' => [
                        'startTime' => null,
                        'endTime' => null,
                        'levelInfoList' => [
                            [
                                'levelNo' => 1,
                                'levelName' => $hotel->name,
                            ],
                            [
                                'levelNo' => 2,
                                'levelName' => $roomType->name,
                            ],
                        ],
                    ],
                    'priceDate' => $date,
                    'marketPrice' => floatval($price->sale_price) / 100,
                    'mtPrice' => floatval($price->sale_price) / 100,
                    'settlementPrice' => floatval($price->cost_price) / 100,
                    'stock' => $stock,
                    'attr' => null,
                ];
            }

            // 构建请求体
            $bodyData = [
                'startTime' => $startDate,
                'endTime' => $endDate,
                'partnerDealId' => $compositeCode, // 使用复合编码作为产品标识
                'body' => $body,
            ];

            $requestData = [
                'partnerId' => $partnerId,
                'body' => $bodyData,
            ];

            Log::info('准备推送打包产品价格到美团', [
                'pkg_product_id' => $pkgProduct->id,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'composite_code' => $compositeCode,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'price_count' => count($body),
            ]);

            $result = $client->notifyLevelPriceStock($requestData);

            // 检查响应
            if (isset($result['code']) && $result['code'] == 200) {
                return [
                    'success' => true,
                    'message' => '推送成功',
                    'data' => $result,
                ];
            }

            return [
                'success' => false,
                'message' => $result['describe'] ?? '推送失败',
                'data' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('推送打包产品价格到美团失败', [
                'pkg_product_id' => $pkgProduct->id,
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '推送异常：' . $e->getMessage(),
            ];
        }
    }
}

