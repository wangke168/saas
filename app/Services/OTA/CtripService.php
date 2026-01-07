<?php

namespace App\Services\OTA;

use App\Enums\OtaPlatform;
use App\Http\Client\CtripClient;
use App\Models\OtaConfig;
use App\Models\OtaPlatform as OtaPlatformModel;
use App\Services\ProductService;
use Illuminate\Support\Facades\Log;

class CtripService
{
    protected ?CtripClient $client = null;

    public function __construct(
        protected ProductService $productService
    ) {}

    protected function getClient(): CtripClient
    {
        if ($this->client === null) {
            // 只从环境变量读取配置
            $config = $this->createConfigFromEnv();

            if (!$config) {
                throw new \Exception('携程配置不存在，请检查 .env 文件中的环境变量配置');
            }

            $this->client = new CtripClient($config);
        }

        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?OtaConfig
    {
        // 检查必需的环境变量是否存在
        if (!env('CTRIP_ACCOUNT_ID') || !env('CTRIP_SECRET_KEY')) {
            return null;
        }

        // 创建临时配置对象（不保存到数据库）
        $config = new OtaConfig();
        $config->account = env('CTRIP_ACCOUNT_ID');
        $config->secret_key = env('CTRIP_SECRET_KEY');
        $config->aes_key = env('CTRIP_ENCRYPT_KEY', '');
        $config->aes_iv = env('CTRIP_ENCRYPT_IV', '');
        
        // API URL 配置（从环境变量读取，CtripClient 会根据接口类型使用对应的 URL）
        // 使用价格API URL作为基础URL（CtripClient会根据接口类型选择正确的URL）
        $config->api_url = env('CTRIP_PRICE_API_URL', 'https://ttdopen.ctrip.com/api/product/price.do');
        $config->callback_url = env('CTRIP_WEBHOOK_URL', '');
        $config->environment = 'production';
        $config->is_active = true;

        return $config;
    }

    /**
     * 同步产品价格到携程
     *
     * @param \App\Models\Product $product 产品
     * @param array|null $dates 指定日期数组，格式：['2025-12-27', '2025-12-28']，如果为null则同步所有价格
     * @param string $dateType DATE_REQUIRED（需指定日期）或 DATE_NOT_REQUIRED（无需指定日期）
     */
    public function syncProductPrice(\App\Models\Product $product, ?array $dates = null, string $dateType = 'DATE_REQUIRED'): array
    {
        $otaProduct = $product->otaProducts()
            ->where('ota_platform_id', \App\Models\OtaPlatform::where('code', OtaPlatform::CTRIP->value)->first()?->id)
            ->first();

        if (!$otaProduct) {
            return ['success' => false, 'message' => '产品未推送到携程'];
        }

        // 检查产品编码
        if (empty($product->code)) {
            return ['success' => false, 'message' => '产品编码(code)为空，请先设置产品编码。携程需要使用产品编码作为supplierOptionId'];
        }

        // 获取价格数据
        $prices = $product->prices();

        if ($dates !== null) {
            $prices->whereIn('date', $dates);
        }

        $priceList = $prices->get();

        if ($priceList->isEmpty()) {
            return ['success' => false, 'message' => '没有价格数据'];
        }

        // 构建请求体
        $bodyData = array_merge([
            'sequenceId' => date('Y-m-d') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
            'dateType' => $dateType,
            'prices' => [],
        ], $this->buildProductIdentifier($product));

        // 转换价格数据，应用加价规则
        // 注意：一个产品可能关联多个房型，同一日期可能有多个房型的价格
        // 需要按日期汇总，同一日期多个房型时取平均值
        if ($dateType === 'DATE_NOT_REQUIRED') {
            // 非指定日期模式：只传一个价格项，不包含日期字段
            // 取第一个价格，应用加价规则
            $firstPrice = $priceList->first();
            if ($firstPrice) {
                $calculatedPrice = $this->productService->calculatePrice(
                    $product,
                    $firstPrice->room_type_id,
                    $firstPrice->date->format('Y-m-d')
                );
                
                $bodyData['prices'][] = [
                    'salePrice' => floatval($calculatedPrice['sale_price']) / 100, // 转换为元（应用加价规则后）
                    'costPrice' => floatval($calculatedPrice['settlement_price']) / 100, // 转换为元（应用加价规则后）
                    // 注意：非指定日期模式不包含 date 字段
                ];
            }
        } else {
            // 指定日期模式：每个日期一个价格项
            // 按日期分组，同一日期可能有多个房型的价格，需要汇总
            $pricesByDate = [];
            foreach ($priceList as $price) {
                $date = $price->date->format('Y-m-d');
                if (!isset($pricesByDate[$date])) {
                    $pricesByDate[$date] = [];
                }
                
                // 应用加价规则计算该房型该日期的价格
                $calculatedPrice = $this->productService->calculatePrice(
                    $product,
                    $price->room_type_id,
                    $date
                );
                
                $pricesByDate[$date][] = $calculatedPrice;
            }
            
            // 按日期汇总价格（同一日期多个房型时，取平均值）
            foreach ($pricesByDate as $date => $prices) {
                // 计算该日期所有房型价格的平均值
                $avgSalePrice = array_sum(array_column($prices, 'sale_price')) / count($prices);
                $avgSettlementPrice = array_sum(array_column($prices, 'settlement_price')) / count($prices);
                
                $priceItem = [
                    'salePrice' => floatval($avgSalePrice) / 100, // 转换为元（应用加价规则后）
                    'costPrice' => floatval($avgSettlementPrice) / 100, // 转换为元（应用加价规则后）
                    'date' => $date,
                ];

                $bodyData['prices'][] = $priceItem;
            }
        }

        Log::info('准备同步价格数据', ['bodyData' => $bodyData]);

        return $this->getClient()->syncPrice($bodyData);
    }

    /**
     * 同步产品库存到携程
     *
     * @param \App\Models\Product $product 产品
     * @param array|null $dates 指定日期数组，格式：['2025-12-27', '2025-12-28']，如果为null则同步所有库存
     * @param string $dateType DATE_REQUIRED（需指定日期）或 DATE_NOT_REQUIRED（无需指定日期）
     */
    public function syncProductStock(\App\Models\Product $product, ?array $dates = null, string $dateType = 'DATE_REQUIRED'): array
    {
        $otaProduct = $product->otaProducts()
            ->where('ota_platform_id', \App\Models\OtaPlatform::where('code', OtaPlatform::CTRIP->value)->first()?->id)
            ->first();

        if (!$otaProduct) {
            return ['success' => false, 'message' => '产品未推送到携程'];
        }

        // 检查产品编码
        if (empty($product->code)) {
            return ['success' => false, 'message' => '产品编码(code)为空，请先设置产品编码。携程需要使用产品编码作为supplierOptionId'];
        }

        // 获取产品的房型
        $roomTypes = $product->prices()->distinct()->pluck('room_type_id');

        if ($roomTypes->isEmpty()) {
            return ['success' => false, 'message' => '产品没有关联房型'];
        }

        // 获取库存数据（按房型汇总）
        $inventories = \App\Models\Inventory::whereIn('room_type_id', $roomTypes);

        if ($dates !== null) {
            $inventories->whereIn('date', $dates);
        }

        $inventoryList = $inventories->get();

        if ($inventoryList->isEmpty()) {
            return ['success' => false, 'message' => '没有库存数据'];
        }

        // 按日期汇总库存（多个房型的库存相加）
        // 注意：如果某个房型在某个日期的 is_closed 为 true，该房型在该日期的库存贡献为 0
        // 如果所有房型都关闭，该日期的总库存就是 0
        $inventoryByDate = [];
        foreach ($inventoryList as $inventory) {
            $date = $inventory->date->format('Y-m-d');
            
            // 检查销售日期范围
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
            
            // 如果不在销售日期范围内，将库存设为0
            if (!$isInSalePeriod) {
                if (!isset($inventoryByDate[$date])) {
                    $inventoryByDate[$date] = [
                        'quantity' => 0,
                        'is_closed' => false,
                    ];
                }
                // 不在销售日期范围内，库存设为0
                $inventoryByDate[$date]['quantity'] = 0;
                $inventoryByDate[$date]['is_closed'] = true; // 标记为关闭状态
                continue;
            }
            
            if (!isset($inventoryByDate[$date])) {
                $inventoryByDate[$date] = [
                    'quantity' => 0,
                    'is_closed' => false, // 记录该日期是否有任何房型关闭
                ];
            }
            
            // 如果库存设置为关闭（is_closed = true），该房型该日期往OTA推送的库存应该是0
            // 如果未关闭，累加该房型的可用库存
            if ($inventory->is_closed) {
                // 如果该房型关闭，标记该日期有关闭状态
                $inventoryByDate[$date]['is_closed'] = true;
            } else {
                $inventoryByDate[$date]['quantity'] += $inventory->available_quantity;
            }
        }
        
        // 如果产品设置了入住天数（stay_days > 1），需要检查连续入住天数的库存
        $stayDays = $product->stay_days;
        if ($stayDays && $stayDays > 1) {
            // 对于每个日期，检查从该日期开始的连续 N 天（N = stay_days）的库存
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                $dateObj = \Carbon\Carbon::parse($date);
                $canAccommodate = true;
                
                // 检查从该日期开始的连续 N 天
                for ($i = 0; $i < $stayDays; $i++) {
                    $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                    
                    // 检查该日期是否有库存记录
                    if (!isset($inventoryByDate[$checkDate])) {
                        // 该日期没有库存记录，无法满足连续入住
                        $canAccommodate = false;
                        break;
                    }
                    
                    // 检查该日期是否关闭或库存为0
                    $checkData = $inventoryByDate[$checkDate];
                    if ($checkData['is_closed'] || $checkData['quantity'] <= 0) {
                        $canAccommodate = false;
                        break;
                    }
                }
                
                // 如果无法满足连续入住，该日期的库存设为0
                if (!$canAccommodate) {
                    $adjustedInventoryByDate[$date] = 0;
                } else {
                    // 可以满足连续入住，使用该日期的实际库存
                    $adjustedInventoryByDate[$date] = $data['quantity'];
                }
            }
            
            // 替换原来的库存数据
            $inventoryByDate = $adjustedInventoryByDate;
        } else {
            // 入住天数为空或1，按原逻辑处理（只考虑关闭状态）
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                // 如果该日期关闭，库存为0；否则使用实际库存
                $adjustedInventoryByDate[$date] = $data['is_closed'] ? 0 : $data['quantity'];
            }
            $inventoryByDate = $adjustedInventoryByDate;
        }

        // 构建请求体
        $bodyData = array_merge([
            'sequenceId' => date('Y-m-d') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
            'dateType' => $dateType,
            'inventorys' => [],
        ], $this->buildProductIdentifier($product));

        // 转换库存数据
        if ($dateType === 'DATE_NOT_REQUIRED') {
            // 非指定日期模式：只传一个库存项，不包含日期字段
            // 汇总所有日期的库存
            $totalQuantity = 0;
            foreach ($inventoryByDate as $quantity) {
                $totalQuantity += (int) $quantity;
            }
            
            // 验证 quantity 必须大于等于 0
            if ($totalQuantity < 0) {
                return ['success' => false, 'message' => '库存数量小于0'];
            }
            
            $bodyData['inventorys'][] = [
                'quantity' => $totalQuantity, // 确保是整数类型
                // 注意：非指定日期模式不包含 date 字段
            ];
        } else {
            // 指定日期模式：每个日期一个库存项
            foreach ($inventoryByDate as $date => $quantity) {
                // 确保 quantity 是整数类型（不是字符串）
                $quantityInt = (int) $quantity;
                
                // 验证 quantity 必须大于等于 0
                if ($quantityInt < 0) {
                    Log::warning('库存数量小于0，跳过', ['date' => $date, 'quantity' => $quantity]);
                    continue;
                }
                
                $inventoryItem = [
                    'quantity' => $quantityInt, // 确保是整数类型
                    'date' => $date,
                ];

                $bodyData['inventorys'][] = $inventoryItem;
            }
        }

        Log::info('准备同步库存数据', ['bodyData' => $bodyData]);

        return $this->getClient()->syncStock($bodyData);
    }

    /**
     * 同步价格到携程（原始方法，保留兼容性）
     */
    public function syncPrice(array $priceData): array
    {
        return $this->getClient()->syncPrice($priceData);
    }

    /**
     * 同步库存到携程（原始方法，保留兼容性）
     */
    public function syncStock(array $stockData): array
    {
        return $this->getClient()->syncStock($stockData);
    }

    /**
     * 确认订单
     * 
     * @param \App\Models\Order $order 订单对象
     * @return array
     */
    public function confirmOrder(\App\Models\Order $order): array
    {
        $client = $this->getClient();
        
        // 构建 items 数组
        $items = [];
        // itemId 优先使用 ctrip_item_id，如果没有则使用订单ID
        $itemId = $order->ctrip_item_id ?: (string)$order->id;
        
        $items[] = [
            'itemId' => $itemId,
            'isCredentialVouchers' => 0, // 固定为0（非凭证类）
        ];
        
        $result = $client->confirmOrder(
            $order->ota_order_no,      // otaOrderId
            $order->order_no,          // supplierOrderId
            '0000',                    // confirmResultCode（成功）
            '确认成功',                 // confirmResultMessage
            1,                         // voucherSender（携程发送）
            $items,                    // items
            []                         // vouchers（可选）
        );
        
        // 检查返回值，如果失败则抛出异常
        if (isset($result['success']) && $result['success'] === false) {
            throw new \Exception('携程订单确认失败：' . ($result['message'] ?? '未知错误'));
        }
        
        return $result;
    }

    /**
     * 订单取消确认
     * 当订单在取消提交后供应商需异步确认进行操作（接受、拒绝），可通过此接口回传供应商的最终确认结果给到携程
     * 
     * @param \App\Models\Order $order 订单对象
     * @return array
     */
    public function confirmCancelOrder(\App\Models\Order $order): array
    {
        $client = $this->getClient();
        
        // 构建 items 数组
        $items = [];
        // itemId 优先使用 ctrip_item_id，如果没有则使用订单ID
        $itemId = $order->ctrip_item_id ?: (string)$order->id;
        
        $item = [
            'itemId' => $itemId,
        ];
        
        // 如果有凭证需要取消，可以在这里添加 vouchers
        // 目前暂时不处理凭证，因为订单模型中可能没有存储凭证信息
        // 如果需要，可以从订单的凭证信息中提取
        // if (!empty($order->vouchers)) {
        //     $item['vouchers'] = $order->vouchers;
        // }
        
        $items[] = $item;
        
        return $client->confirmCancelOrder(
            $order->ota_order_no,      // otaOrderId
            $order->order_no,          // supplierOrderId
            '0000',                    // confirmResultCode（成功）
            '确认成功',                 // confirmResultMessage
            $items                     // items
        );
    }

    /**
     * 订单核销通知
     * 
     * @param string $otaOrderId 携程订单号
     * @param string $supplierOrderId 供应商订单号
     * @param string $itemId 订单项编号
     * @param string $useStartDate 实际使用开始日期，格式：yyyy-MM-dd
     * @param string $useEndDate 实际使用结束日期，格式：yyyy-MM-dd
     * @param int $quantity 订单总份数
     * @param int $useQuantity 订单已核销总份数
     * @param array $passengers 已核销的份数所对应的出行人数组
     * @param array $vouchers 已核销的份数所对应的凭证数组（可选）
     * @return array
     */
    public function notifyOrderConsumed(
        string $otaOrderId,
        string $supplierOrderId,
        string $itemId,
        string $useStartDate,
        string $useEndDate,
        int $quantity,
        int $useQuantity,
        array $passengers = [],
        array $vouchers = []
    ): array {
        return $this->getClient()->notifyOrderConsumed(
            $otaOrderId,
            $supplierOrderId,
            $itemId,
            $useStartDate,
            $useEndDate,
            $quantity,
            $useQuantity,
            $passengers,
            $vouchers
        );
    }

    /**
     * 生成携程产品编码（格式：酒店编码|房型编码|产品编码）
     */
    public function generateCtripProductCode(string $productCode, string $hotelCode, string $roomTypeCode): string
    {
        return "{$hotelCode}|{$roomTypeCode}|{$productCode}";
    }

    /**
     * 构建携程请求体中的产品标识字段
     * 优先使用 supplierOptionId（供应商PLU），如果没有则使用 otaOptionId（携程资源编号）
     * 
     * @param \App\Models\Product $product 产品
     * @param string|null $ctripProductCode 携程产品编码（格式：酒店编码|房型编码|产品编码）
     * @return array 包含 supplierOptionId 或 otaOptionId 的数组
     */
    protected function buildProductIdentifier(\App\Models\Product $product, ?string $ctripProductCode = null): array
    {
        // 优先使用 supplierOptionId（供应商PLU）
        if ($ctripProductCode) {
            return ['supplierOptionId' => trim($ctripProductCode)];
        }

        // 如果没有传入 ctripProductCode，尝试使用产品编码
        if (!empty($product->code)) {
            return ['supplierOptionId' => trim((string)$product->code)];
        }

        // 如果 supplierOptionId 不可用，再使用 otaOptionId（携程资源编号）
        $otaProduct = $product->otaProducts()
            ->where('ota_platform_id', \App\Models\OtaPlatform::where('code', OtaPlatform::CTRIP->value)->first()?->id)
            ->first();

        if ($otaProduct && !empty($otaProduct->ota_product_id)) {
            return ['otaOptionId' => (int)$otaProduct->ota_product_id];
        }

        throw new \Exception('无法构建产品标识：缺少 supplierOptionId 或 otaOptionId');
    }

    /**
     * 按"产品-酒店-房型"组合同步价格到携程
     * 
     * @param \App\Models\Product $product 产品
     * @param \App\Models\Hotel $hotel 酒店
     * @param \App\Models\RoomType $roomType 房型
     * @param array|null $dates 指定日期数组，格式：['2025-12-27', '2025-12-28']，如果为null则同步所有价格
     * @param string $dateType DATE_REQUIRED（需指定日期）或 DATE_NOT_REQUIRED（无需指定日期）
     * @return array
     */
    public function syncProductPriceByCombo(
        \App\Models\Product $product,
        \App\Models\Hotel $hotel,
        \App\Models\RoomType $roomType,
        ?array $dates = null,
        string $dateType = 'DATE_REQUIRED'
    ): array {
        // 检查编码
        if (empty($product->code) || empty($hotel->code) || empty($roomType->code)) {
            return ['success' => false, 'message' => '产品编码、酒店编码或房型编码为空'];
        }

        // 生成携程产品编码
        $ctripProductCode = $this->generateCtripProductCode($product->code, $hotel->code, $roomType->code);

        // 获取该房型的价格数据
        $prices = $product->prices()->where('room_type_id', $roomType->id);

        if ($dates !== null) {
            $prices->whereIn('date', $dates);
        }

        $priceList = $prices->get();

        if ($priceList->isEmpty()) {
            return ['success' => false, 'message' => '没有价格数据'];
        }

        // 构建请求体
        $bodyData = array_merge([
            'sequenceId' => date('Ymd') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
            'dateType' => $dateType,
            'prices' => [],
        ], $this->buildProductIdentifier($product, $ctripProductCode));

        // 转换价格数据，应用加价规则
        if ($dateType === 'DATE_NOT_REQUIRED') {
            // 非指定日期模式：只传一个价格项，不包含日期字段
            $firstPrice = $priceList->first();
            if ($firstPrice) {
                $calculatedPrice = $this->productService->calculatePrice(
                    $product,
                    $roomType->id,
                    $firstPrice->date->format('Y-m-d')
                );
                
                $bodyData['prices'][] = [
                    'salePrice' => floatval($calculatedPrice['sale_price']) / 100,
                    'costPrice' => floatval($calculatedPrice['settlement_price']) / 100,
                ];
            }
        } else {
            // 指定日期模式：每个日期一个价格项
            foreach ($priceList as $price) {
                $date = $price->date->format('Y-m-d');
                
                // 应用加价规则计算价格
                $calculatedPrice = $this->productService->calculatePrice(
                    $product,
                    $roomType->id,
                    $date
                );
                
                $bodyData['prices'][] = [
                    'salePrice' => floatval($calculatedPrice['sale_price']) / 100,
                    'costPrice' => floatval($calculatedPrice['settlement_price']) / 100,
                    'date' => $date,
                ];
            }
        }

        Log::info('准备同步组合价格数据', [
            'product_code' => $product->code,
            'hotel_code' => $hotel->code,
            'room_type_code' => $roomType->code,
            'ctrip_product_code' => $ctripProductCode,
            'bodyData' => $bodyData,
        ]);

        return $this->getClient()->syncPrice($bodyData);
    }

    /**
     * 按"产品-酒店-房型"组合同步库存到携程
     * 
     * @param \App\Models\Product $product 产品
     * @param \App\Models\Hotel $hotel 酒店
     * @param \App\Models\RoomType $roomType 房型
     * @param array|null $dates 指定日期数组，格式：['2025-12-27', '2025-12-28']，如果为null则同步所有库存
     * @param string $dateType DATE_REQUIRED（需指定日期）或 DATE_NOT_REQUIRED（无需指定日期）
     * @return array
     */
    public function syncProductStockByCombo(
        \App\Models\Product $product,
        \App\Models\Hotel $hotel,
        \App\Models\RoomType $roomType,
        ?array $dates = null,
        string $dateType = 'DATE_REQUIRED'
    ): array {
        // 检查编码
        if (empty($product->code) || empty($hotel->code) || empty($roomType->code)) {
            return ['success' => false, 'message' => '产品编码、酒店编码或房型编码为空'];
        }

        // 生成携程产品编码
        $ctripProductCode = $this->generateCtripProductCode($product->code, $hotel->code, $roomType->code);

        // 获取该房型的库存数据
        $inventories = \App\Models\Inventory::where('room_type_id', $roomType->id);

        if ($dates !== null) {
            $inventories->whereIn('date', $dates);
        }

        $inventoryList = $inventories->get();

        if ($inventoryList->isEmpty()) {
            return ['success' => false, 'message' => '没有库存数据'];
        }

        // 按日期汇总库存
        $inventoryByDate = [];
        foreach ($inventoryList as $inventory) {
            $date = $inventory->date->format('Y-m-d');
            
            // 检查销售日期范围
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
            
            // 如果不在销售日期范围内，将库存设为0
            if (!$isInSalePeriod) {
                if (!isset($inventoryByDate[$date])) {
                    $inventoryByDate[$date] = [
                        'quantity' => 0,
                        'is_closed' => false,
                    ];
                }
                // 不在销售日期范围内，库存设为0
                $inventoryByDate[$date]['quantity'] = 0;
                $inventoryByDate[$date]['is_closed'] = true; // 标记为关闭状态
                continue;
            }
            
            if (!isset($inventoryByDate[$date])) {
                $inventoryByDate[$date] = [
                    'quantity' => 0,
                    'is_closed' => false,
                ];
            }
            
            if ($inventory->is_closed) {
                $inventoryByDate[$date]['is_closed'] = true;
            } else {
                $inventoryByDate[$date]['quantity'] += $inventory->available_quantity;
            }
        }

        // 如果产品设置了入住天数（stay_days > 1），需要检查连续入住天数的库存
        $stayDays = $product->stay_days;
        if ($stayDays && $stayDays > 1) {
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                $dateObj = \Carbon\Carbon::parse($date);
                $canAccommodate = true;
                $checkDetails = []; // 记录检查详情
                
                Log::debug('检查连续入住库存', [
                    'date' => $date,
                    'original_quantity' => $data['quantity'],
                    'is_closed' => $data['is_closed'],
                    'stay_days' => $stayDays,
                ]);
                
                for ($i = 0; $i < $stayDays; $i++) {
                    $checkDate = $dateObj->copy()->addDays($i)->format('Y-m-d');
                    
                    // 如果日期不在查询范围内，查询数据库获取库存（用于判断），但不推送
                    if (!isset($inventoryByDate[$checkDate])) {
                        // 查询数据库获取该日期的库存
                        $missingInventory = \App\Models\Inventory::where('room_type_id', $roomType->id)
                            ->where('date', $checkDate)
                            ->first();
                        
                        if (!$missingInventory) {
                            // 该日期没有库存记录，无法满足连续入住
                            $canAccommodate = false;
                            $checkDetails[] = [
                                'check_date' => $checkDate,
                                'status' => 'not_found',
                                'reason' => '数据库中没有库存记录',
                            ];
                            Log::debug('连续入住检查：日期不在查询范围内且数据库中没有记录', [
                                'date' => $date,
                                'check_date' => $checkDate,
                                'room_type_id' => $roomType->id,
                            ]);
                            break;
                        }
                        
                        // 检查销售日期范围
                        $isInSalePeriod = true;
                        if ($product->sale_start_date || $product->sale_end_date) {
                            $saleStartDate = $product->sale_start_date ? $product->sale_start_date->format('Y-m-d') : null;
                            $saleEndDate = $product->sale_end_date ? $product->sale_end_date->format('Y-m-d') : null;
                            
                            if ($saleStartDate && $checkDate < $saleStartDate) {
                                $isInSalePeriod = false;
                            }
                            if ($saleEndDate && $checkDate > $saleEndDate) {
                                $isInSalePeriod = false;
                            }
                        }
                        
                        // 如果不在销售日期范围内、关闭或库存为0，无法满足连续入住
                        if (!$isInSalePeriod || $missingInventory->is_closed || $missingInventory->available_quantity <= 0) {
                            $canAccommodate = false;
                            $checkDetails[] = [
                                'check_date' => $checkDate,
                                'status' => 'not_available',
                                'is_in_sale_period' => $isInSalePeriod,
                                'is_closed' => $missingInventory->is_closed,
                                'available_quantity' => $missingInventory->available_quantity,
                                'reason' => !$isInSalePeriod ? '不在销售日期范围内' : ($missingInventory->is_closed ? '库存已关闭' : '库存为0'),
                            ];
                            Log::debug('连续入住检查：日期不在查询范围内且不满足条件', [
                                'date' => $date,
                                'check_date' => $checkDate,
                                'is_in_sale_period' => $isInSalePeriod,
                                'is_closed' => $missingInventory->is_closed,
                                'available_quantity' => $missingInventory->available_quantity,
                                'room_type_id' => $roomType->id,
                            ]);
                            break;
                        }
                        
                        $checkDetails[] = [
                            'check_date' => $checkDate,
                            'status' => 'available',
                            'is_in_sale_period' => $isInSalePeriod,
                            'is_closed' => $missingInventory->is_closed,
                            'available_quantity' => $missingInventory->available_quantity,
                        ];
                        
                        // 继续检查下一个日期（不添加到 $inventoryByDate，因为不推送）
                        continue;
                    }
                    
                    // 日期在查询范围内，直接检查
                    $checkData = $inventoryByDate[$checkDate];
                    if ($checkData['is_closed'] || $checkData['quantity'] <= 0) {
                        $canAccommodate = false;
                        $checkDetails[] = [
                            'check_date' => $checkDate,
                            'status' => 'not_available',
                            'is_closed' => $checkData['is_closed'],
                            'quantity' => $checkData['quantity'],
                            'reason' => $checkData['is_closed'] ? '库存已关闭' : '库存为0',
                        ];
                        Log::debug('连续入住检查：日期在查询范围内但不满足条件', [
                            'date' => $date,
                            'check_date' => $checkDate,
                            'is_closed' => $checkData['is_closed'],
                            'quantity' => $checkData['quantity'],
                        ]);
                        break;
                    }
                    
                    $checkDetails[] = [
                        'check_date' => $checkDate,
                        'status' => 'available',
                        'is_closed' => $checkData['is_closed'],
                        'quantity' => $checkData['quantity'],
                    ];
                }
                
                // 如果满足连续入住，使用该日期的实际库存；否则设为0
                $adjustedQuantity = $canAccommodate ? $data['quantity'] : 0;
                $adjustedInventoryByDate[$date] = $adjustedQuantity;
                
                Log::info('连续入住检查结果', [
                    'date' => $date,
                    'original_quantity' => $data['quantity'],
                    'adjusted_quantity' => $adjustedQuantity,
                    'can_accommodate' => $canAccommodate,
                    'check_details' => $checkDetails,
                ]);
            }
            
            $inventoryByDate = $adjustedInventoryByDate;
        } else {
            $adjustedInventoryByDate = [];
            foreach ($inventoryByDate as $date => $data) {
                $adjustedInventoryByDate[$date] = $data['is_closed'] ? 0 : $data['quantity'];
            }
            $inventoryByDate = $adjustedInventoryByDate;
        }

        // 构建请求体
        $bodyData = array_merge([
            'sequenceId' => date('Ymd') . str_replace('-', '', \Illuminate\Support\Str::uuid()->toString()),
            'dateType' => $dateType,
            'inventorys' => [],
        ], $this->buildProductIdentifier($product, $ctripProductCode));

        // 转换库存数据
        if ($dateType === 'DATE_NOT_REQUIRED') {
            $totalQuantity = 0;
            foreach ($inventoryByDate as $quantity) {
                $totalQuantity += (int) $quantity;
            }
            
            if ($totalQuantity < 0) {
                return ['success' => false, 'message' => '库存数量小于0'];
            }
            
            $bodyData['inventorys'][] = [
                'quantity' => $totalQuantity,
            ];
        } else {
            foreach ($inventoryByDate as $date => $quantity) {
                $quantityInt = (int) $quantity;
                
                if ($quantityInt < 0) {
                    Log::warning('库存数量小于0，跳过', ['date' => $date, 'quantity' => $quantity]);
                    continue;
                }
                
                $bodyData['inventorys'][] = [
                    'quantity' => $quantityInt,
                    'date' => $date,
                ];
            }
        }

        Log::info('准备同步组合库存数据', [
            'product_code' => $product->code,
            'hotel_code' => $hotel->code,
            'room_type_code' => $roomType->code,
            'ctrip_product_code' => $ctripProductCode,
            'bodyData' => $bodyData,
        ]);

        return $this->getClient()->syncStock($bodyData);
    }
}
