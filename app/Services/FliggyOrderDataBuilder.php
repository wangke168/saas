<?php

namespace App\Services;

use App\Http\Client\FliggyDistributionClient;
use App\Models\Order;
use App\Models\ResourceConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FliggyOrderDataBuilder
{
    protected ?FliggyDistributionClient $client = null;
    protected ?ResourceConfig $config = null;

    /**
     * 设置资源配置（由 FliggyDistributionService 调用）
     */
    public function setConfig(ResourceConfig $config): self
    {
        $this->config = $config;
        $this->client = null; // 重置客户端
        return $this;
    }

    /**
     * 获取客户端
     */
    protected function getClient(): FliggyDistributionClient
    {
        if ($this->client === null) {
            if (!$this->config) {
                throw new \Exception('FliggyOrderDataBuilder: 配置未设置，请先调用 setConfig()');
            }
            $this->client = new FliggyDistributionClient($this->config);
        }
        return $this->client;
    }

    /**
     * 构建订单请求数据（用于 validateOrder 和 createOrder）
     * 
     * @param Order $order 订单对象
     * @param string $fliggyProductId 飞猪产品ID
     * @return array 订单请求数据
     */
    public function buildOrderData(Order $order, string $fliggyProductId): array
    {
        Log::info('FliggyOrderDataBuilder: 开始构建订单数据', [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'fliggy_product_id' => $fliggyProductId,
        ]);

        // 1. 获取飞猪价格（实时查询）
        $fliggyPrice = $this->getFliggyPrice($fliggyProductId, $order->check_in_date);
        
        if (!$fliggyPrice) {
            throw new \Exception('无法获取飞猪产品价格，请检查产品配置');
        }

        // 2. 构建 productInfo
        $productInfo = [
            'productId' => $fliggyProductId,
            'price' => $fliggyPrice['price'], // 单位：分
            'quantity' => $order->room_count ?? 1,
            'travelDate' => $this->formatTravelDate($order->check_in_date),
        ];

        // 3. 构建 contactInfo
        $contactInfo = [
            'name' => $order->contact_name ?? '',
            'mobile' => $order->contact_phone ?? '',
            'email' => $order->contact_email ?? '',
            'certificatesType' => $this->mapCertificatesType($order->real_name_type ?? 0),
            'certificates' => $this->getContactCertificates($order),
        ];

        // 4. 构建 travellerInfos
        $travellerInfos = $this->buildTravellerInfos($order);

        // 5. 计算总价（使用飞猪价格，单位：分）
        $totalPrice = $fliggyPrice['price'] * ($order->room_count ?? 1);

        $orderData = [
            'outOrderId' => $order->order_no,
            'productInfo' => $productInfo,
            'contactInfo' => $contactInfo,
            'travellerInfos' => $travellerInfos,
            'totalPrice' => $totalPrice,
        ];

        Log::info('FliggyOrderDataBuilder: 订单数据构建完成', [
            'order_id' => $order->id,
            'fliggy_price' => $fliggyPrice['price'],
            'our_price' => $order->total_amount ? ($order->total_amount * 100) : 0, // 转换为分
            'price_diff' => $totalPrice - ($order->total_amount ? ($order->total_amount * 100) : 0),
            'total_price' => $totalPrice,
            'traveller_count' => count($travellerInfos),
        ]);

        return $orderData;
    }

    /**
     * 实时查询飞猪价格
     * 
     * @param string $fliggyProductId 飞猪产品ID
     * @param \Carbon\Carbon|string|null $date 使用日期
     * @return array|null ['price' => int, 'stock' => int] (价格单位：分)，如果查询失败返回null
     */
    protected function getFliggyPrice(string $fliggyProductId, $date): ?array
    {
        try {
            // 转换日期为时间戳（13位，毫秒）
            $dateTimestamp = null;
            if ($date) {
                if ($date instanceof Carbon) {
                    $dateTimestamp = $date->timestamp * 1000;
                } else {
                    $dateTimestamp = Carbon::parse($date)->timestamp * 1000;
                }
            }

            // 查询价格/库存
            $result = $this->getClient()->queryProductPriceStock(
                $fliggyProductId,
                $dateTimestamp,
                $dateTimestamp
            );

            if (!($result['success'] ?? false)) {
                Log::error('FliggyOrderDataBuilder: 查询飞猪价格失败', [
                    'fliggy_product_id' => $fliggyProductId,
                    'date' => $date,
                    'error' => $result['message'] ?? '未知错误',
                ]);
                return null;
            }

            $data = $result['data'] ?? [];
            
            // 解析价格数据
            // 根据飞猪接口文档，返回的数据结构可能包含 calendarStock 或 totalStock
            $price = 0;
            $stock = 0;

            if (isset($data['calendarStock']) && is_array($data['calendarStock'])) {
                // 日历库存模式
                foreach ($data['calendarStock'] as $stockItem) {
                    if (isset($stockItem['date']) && isset($stockItem['distributionPrice'])) {
                        $itemDate = $stockItem['date'];
                        // 如果是查询特定日期，只取该日期的价格
                        if ($dateTimestamp && $itemDate == $dateTimestamp) {
                            $price = (int)($stockItem['distributionPrice'] * 100); // 转换为分
                            $stock = (int)($stockItem['stock'] ?? 0);
                            break;
                        } elseif (!$dateTimestamp) {
                            // 如果没有指定日期，取第一个
                            $price = (int)($stockItem['distributionPrice'] * 100);
                            $stock = (int)($stockItem['stock'] ?? 0);
                            break;
                        }
                    }
                }
            } elseif (isset($data['distributionPrice'])) {
                // 总库存模式
                $price = (int)($data['distributionPrice'] * 100); // 转换为分
                $stock = (int)($data['stock'] ?? 0);
            }

            if ($price <= 0) {
                Log::warning('FliggyOrderDataBuilder: 飞猪价格无效', [
                    'fliggy_product_id' => $fliggyProductId,
                    'date' => $date,
                    'data' => $data,
                ]);
                return null;
            }

            Log::info('FliggyOrderDataBuilder: 查询飞猪价格成功', [
                'fliggy_product_id' => $fliggyProductId,
                'date' => $date,
                'price' => $price,
                'stock' => $stock,
            ]);

            return [
                'price' => $price,
                'stock' => $stock,
            ];

        } catch (\Exception $e) {
            Log::error('FliggyOrderDataBuilder: 查询飞猪价格异常', [
                'fliggy_product_id' => $fliggyProductId,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 格式化出行日期
     * 
     * @param \Carbon\Carbon|string|null $date
     * @return string YYYYMMDD 格式
     */
    protected function formatTravelDate($date): string
    {
        if ($date instanceof Carbon) {
            return $date->format('Ymd');
        } elseif ($date) {
            return Carbon::parse($date)->format('Ymd');
        }
        return '';
    }

    /**
     * 获取联系人证件号码
     * 
     * @param Order $order
     * @return string
     */
    protected function getContactCertificates(Order $order): string
    {
        // 优先级：1. credential_list[0] 2. guest_info[0] 3. card_no
        if (!empty($order->credential_list) && is_array($order->credential_list)) {
            $firstCredential = $order->credential_list[0] ?? [];
            if (!empty($firstCredential['credentialNo'] ?? $firstCredential['credential_no'] ?? '')) {
                return $firstCredential['credentialNo'] ?? $firstCredential['credential_no'] ?? '';
            }
        }

        if (!empty($order->guest_info) && is_array($order->guest_info)) {
            $firstGuest = $order->guest_info[0] ?? [];
            if (!empty($firstGuest['idCode'] ?? $firstGuest['id_code'] ?? $firstGuest['cardNo'] ?? $firstGuest['card_no'] ?? '')) {
                return $firstGuest['idCode'] ?? $firstGuest['id_code'] ?? $firstGuest['cardNo'] ?? $firstGuest['card_no'] ?? '';
            }
        }

        return $order->card_no ?? '';
    }

    /**
     * 构建出行人信息列表
     * 
     * @param Order $order
     * @return array
     */
    protected function buildTravellerInfos(Order $order): array
    {
        $travellerInfos = [];

        // 从 guest_info 构建
        if (!empty($order->guest_info) && is_array($order->guest_info)) {
            foreach ($order->guest_info as $guest) {
                $name = $guest['name'] ?? $guest['Name'] ?? '';
                $mobile = $guest['mobile'] ?? $guest['Mobile'] ?? $guest['phone'] ?? $guest['Phone'] ?? '';
                $certificatesType = $this->mapCertificatesType($guest['credentialType'] ?? $guest['credential_type'] ?? $order->real_name_type ?? 0);
                $certificates = $guest['idCode'] ?? $guest['id_code'] ?? $guest['cardNo'] ?? $guest['card_no'] ?? '';
                $travellerType = $guest['travellerType'] ?? $guest['traveller_type'] ?? 1; // 默认成人

                if (!empty($name)) {
                    $travellerInfos[] = [
                        'name' => $name,
                        'mobile' => $mobile,
                        'certificatesType' => $certificatesType,
                        'certificates' => $certificates,
                        'travellerType' => $travellerType,
                    ];
                }
            }
        }

        // 如果 guest_info 为空，从 credential_list 构建
        if (empty($travellerInfos) && !empty($order->credential_list) && is_array($order->credential_list)) {
            foreach ($order->credential_list as $credential) {
                $name = $order->contact_name ?? '';
                $mobile = $order->contact_phone ?? '';
                $certificatesType = $this->mapCertificatesType($credential['credentialType'] ?? $credential['credential_type'] ?? $order->real_name_type ?? 0);
                $certificates = $credential['credentialNo'] ?? $credential['credential_no'] ?? '';

                if (!empty($name) || !empty($certificates)) {
                    $travellerInfos[] = [
                        'name' => $name,
                        'mobile' => $mobile,
                        'certificatesType' => $certificatesType,
                        'certificates' => $certificates,
                        'travellerType' => 1, // 默认成人
                    ];
                }
            }
        }

        // 如果仍然为空，至少创建一个（使用联系人信息）
        if (empty($travellerInfos)) {
            $travellerInfos[] = [
                'name' => $order->contact_name ?? '',
                'mobile' => $order->contact_phone ?? '',
                'certificatesType' => $this->mapCertificatesType($order->real_name_type ?? 0),
                'certificates' => $this->getContactCertificates($order),
                'travellerType' => 1, // 默认成人
            ];
        }

        Log::info('FliggyOrderDataBuilder: 构建出行人信息', [
            'order_id' => $order->id,
            'traveller_count' => count($travellerInfos),
        ]);

        return $travellerInfos;
    }

    /**
     * 映射证件类型
     * 
     * 飞猪证件类型：
     * 3 - 身份证
     * 19 - 护照
     * 20 - 军官证
     * 21 - 士兵证
     * 22 - 回乡证
     * 23 - 台胞证
     * 24 - 国际海员证
     * 25 - 外国人永久居留证
     * 26 - 其他
     * 
     * @param int $ourType 本地证件类型
     * @return int 飞猪证件类型
     */
    protected function mapCertificatesType(int $ourType): int
    {
        // 根据实际业务映射，这里提供默认映射
        // 0 或未定义 -> 身份证(3)
        // 可以根据实际业务需求调整映射关系
        return match($ourType) {
            1 => 3,   // 身份证
            2 => 19,  // 护照
            3 => 20,  // 军官证
            4 => 21,  // 士兵证
            5 => 22,  // 回乡证
            6 => 23,  // 台胞证
            default => 3, // 默认身份证
        };
    }
}

