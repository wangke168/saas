<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform;
use App\Http\Client\MeituanClient;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\ProductService;
use Illuminate\Support\Facades\Log;

class MeituanService
{
    protected ?MeituanClient $client = null;

    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * 获取MeituanClient实例
     */
    protected function getClient(): MeituanClient
    {
        if ($this->client === null) {
            // 优先使用环境变量配置（如果存在）
            $config = $this->createConfigFromEnv();
            
            // 如果环境变量配置不存在，尝试从数据库读取
            if (!$config) {
                $platform = OtaPlatformModel::where('code', OtaPlatform::MEITUAN->value)->first();
                $config = $platform?->config;
            }

            if (!$config) {
                throw new \Exception('美团配置不存在，请检查数据库配置或环境变量');
            }

            $this->client = new MeituanClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?OtaConfig
    {
        // 检查环境变量是否存在
        if (!env('MEITUAN_PARTNER_ID') || !env('MEITUAN_APP_KEY') || !env('MEITUAN_APP_SECRET')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new OtaConfig();
        $config->account = env('MEITUAN_PARTNER_ID'); // PartnerId存储在account字段
        $config->secret_key = env('MEITUAN_APP_KEY'); // AppKey存储在secret_key字段
        $config->aes_key = env('MEITUAN_APP_SECRET'); // AppSecret存储在aes_key字段
        $config->aes_iv = env('MEITUAN_AES_KEY', ''); // AES密钥存储在aes_iv字段
        
        // API URL 配置
        // 根据美团文档，正确的API地址是 https://connectivity-adapter.meituan.com
        $config->api_url = env('MEITUAN_API_URL', 'https://connectivity-adapter.meituan.com');
        $config->callback_url = env('MEITUAN_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 生成partnerPrimaryKey（SKU唯一标识）
     * MD5(hotel_id|room_type_id|date)
     * 
     * @param int $hotelId 酒店ID
     * @param int $roomTypeId 房型ID
     * @param string $date 日期（Y-m-d格式）
     * @return string
     */
    public function generatePartnerPrimaryKey(int $hotelId, int $roomTypeId, string $date): string
    {
        $key = "{$hotelId}|{$roomTypeId}|{$date}";
        return md5($key);
    }

    /**
     * 构建多层价格日历数据（两层：酒店和房型）
     * 
     * @param \App\Models\Product $product 产品
     * @param \App\Models\Hotel $hotel 酒店
     * @param \App\Models\RoomType $roomType 房型
     * @param string $startDate 开始日期（Y-m-d格式）
     * @param string $endDate 结束日期（Y-m-d格式）
     * @return array
     */
    public function buildLevelPriceStockData(
        \App\Models\Product $product,
        \App\Models\Hotel $hotel,
        \App\Models\RoomType $roomType,
        string $startDate,
        string $endDate
    ): array {
        $body = [];

        // 生成日期范围
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $dates = [];
        while ($start->lte($end)) {
            $dates[] = $start->format('Y-m-d');
            $start->addDay();
        }

        foreach ($dates as $date) {
            // 计算价格
            $priceData = $this->productService->calculatePrice($product, $roomType->id, $date);
            
            // 获取库存
            $inventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                ->where('date', $date)
                ->first();

            // 检查销售日期范围
            $stock = 0;
            $isClosed = true;
            if ($inventory) {
                $isInSalePeriod = true;
                if ($product->sale_start_date || $product->sale_end_date) {
                    $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                    $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                    
                    if ($saleStartDate && $date < $saleStartDate) {
                        $isInSalePeriod = false;
                    }
                    if ($saleEndDate && $date > $saleEndDate) {
                        $isInSalePeriod = false;
                    }
                }
                
                if ($isInSalePeriod && !$inventory->is_closed) {
                    $stock = $inventory->available_quantity;
                    $isClosed = false;
                }
            }

            // 生成partnerPrimaryKey
            $partnerPrimaryKey = $this->generatePartnerPrimaryKey($hotel->id, $roomType->id, $date);

            $body[] = [
                'partnerPrimaryKey' => $partnerPrimaryKey,
                'skuInfo' => [
                    'startTime' => '14:00',
                    'endTime' => '16:00',
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
                // 价格从"分"转换为"元"（美团接口要求单位：元，保留两位小数）
                'marketPrice' => round(floatval($priceData['market_price'] ?? $priceData['sale_price']) / 100, 2),
                'mtPrice' => round(floatval($priceData['sale_price']) / 100, 2),
                'settlementPrice' => round(floatval($priceData['settlement_price']) / 100, 2),
                'stock' => $stock,
                'attr' => null,
            ];
        }

        return $body;
    }

    /**
     * 同步多层价格日历变化通知V2（主动推送）
     * 
     * @param \App\Models\Product $product 产品
     * @param \App\Models\Hotel $hotel 酒店
     * @param \App\Models\RoomType $roomType 房型
     * @param string $startDate 开始日期（Y-m-d格式）
     * @param string $endDate 结束日期（Y-m-d格式）
     * @return array
     */
    public function syncLevelPriceStock(
        \App\Models\Product $product,
        \App\Models\Hotel $hotel,
        \App\Models\RoomType $roomType,
        string $startDate,
        string $endDate
    ): array {
        try {
            $client = $this->getClient();
            $partnerId = $client->getPartnerId();

            // 构建请求数据
            $body = $this->buildLevelPriceStockData($product, $hotel, $roomType, $startDate, $endDate);

            if (empty($body)) {
                return [
                    'success' => false,
                    'message' => '没有价格库存数据',
                ];
            }

            // 美团请求格式：{partnerId, body: "加密的JSON字符串"}
            // body字段需要包含：startTime, endTime, partnerDealId, body数组
            $bodyData = [
                'startTime' => $startDate,
                'endTime' => $endDate,
                'partnerDealId' => $product->code,
                'body' => $body,
            ];

            $requestData = [
                'partnerId' => $partnerId,
                'body' => $bodyData, // body字段是数组，会在MeituanClient中加密
            ];

            Log::info('准备同步美团多层价格日历数据', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'body_count' => count($body),
            ]);

            $result = $client->notifyLevelPriceStock($requestData);

            // 检查响应
            if (isset($result['code']) && $result['code'] == 200) {
                Log::info('同步美团多层价格日历：成功', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                ]);

                return [
                    'success' => true,
                    'message' => '同步成功',
                    'data' => $result,
                ];
            } else {
                Log::error('同步美团多层价格日历：失败', [
                    'product_id' => $product->id,
                    'hotel_id' => $hotel->id,
                    'room_type_id' => $roomType->id,
                    'result' => $result,
                ]);

                return [
                    'success' => false,
                    'message' => $result['describe'] ?? '同步失败',
                    'data' => $result,
                ];
            }
        } catch (\Exception $e) {
            Log::error('同步美团多层价格日历异常', [
                'product_id' => $product->id,
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomType->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '同步异常：' . $e->getMessage(),
            ];
        }
    }
}
