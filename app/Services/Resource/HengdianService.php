<?php

namespace App\Services\Resource;

use App\Http\Client\HengdianClient;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Models\SoftwareProvider;
use App\Models\ExceptionOrder;
use App\Enums\ExceptionOrderType;
use App\Enums\ExceptionOrderStatus;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Log;

class HengdianService implements ResourceServiceInterface
{
    protected ?HengdianClient $client = null;

    /**
     * 根据订单获取对应的客户端
     * 现在配置是景区级别的，需要根据订单的景区来获取配置
     */
    protected function getClient(?Order $order = null): HengdianClient
    {
        if ($this->client === null) {
            Log::info('HengdianService::getClient 开始获取配置', [
                'order_id' => $order?->id,
                'has_order' => $order !== null,
                'order_hotel_id' => $order?->hotel_id,
            ]);
            
            $config = null;
            
            // 如果提供了订单，根据订单的景区获取配置
            if ($order) {
                $scenicSpot = $order->hotel->scenicSpot ?? null;
                Log::info('HengdianService::getClient: 尝试从订单景区获取配置', [
                    'order_id' => $order->id,
                    'has_scenic_spot' => $scenicSpot !== null,
                    'scenic_spot_id' => $scenicSpot?->id,
                    'resource_config_id' => $scenicSpot?->resource_config_id,
                ]);
                
                if ($scenicSpot && $scenicSpot->resource_config_id) {
                    $config = ResourceConfig::find($scenicSpot->resource_config_id);
                    Log::info('HengdianService::getClient: 从景区配置获取成功', [
                        'order_id' => $order->id,
                        'config_id' => $config?->id,
                        'config_api_url' => $config?->api_url,
                    ]);
                }
            }
            
            // 如果没有找到配置，尝试使用第一个配置（向后兼容）
            if (!$config) {
                Log::info('HengdianService::getClient: 尝试从软件服务商获取配置', [
                    'order_id' => $order?->id,
                ]);
                
                $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                Log::info('HengdianService::getClient: 软件服务商查询结果', [
                    'order_id' => $order?->id,
                    'provider_id' => $provider?->id,
                    'provider_name' => $provider?->name,
                ]);
                
                $config = $provider?->resourceConfigs()->first();
                Log::info('HengdianService::getClient: 从软件服务商配置获取结果', [
                    'order_id' => $order?->id,
                    'config_id' => $config?->id,
                    'config_api_url' => $config?->api_url,
                ]);
            }
            
            // 如果还是没有配置，尝试从.env创建临时配置
            if (!$config) {
                Log::info('HengdianService::getClient: 尝试从环境变量创建配置', [
                    'order_id' => $order?->id,
                    'has_api_url' => !empty(env('HENGDIAN_API_URL')),
                    'has_username' => !empty(env('HENGDIAN_USERNAME')),
                    'has_password' => !empty(env('HENGDIAN_PASSWORD')),
                ]);
                
                $config = $this->createConfigFromEnv();
                Log::info('HengdianService::getClient: 从环境变量创建配置结果', [
                    'order_id' => $order?->id,
                    'config_created' => $config !== null,
                    'config_api_url' => $config?->api_url,
                ]);
            }
            
            if (!$config) {
                Log::error('HengdianService::getClient: 配置不存在', [
                    'order_id' => $order?->id,
                    'error' => '资源方配置不存在，请检查数据库配置或环境变量',
                ]);
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }
            
            // 获取订单的OTA平台代码
            $otaPlatformCode = $order?->otaPlatform?->code?->value ?? null;
            
            Log::info('HengdianService::getClient: 准备创建 HengdianClient', [
                'order_id' => $order?->id,
                'config_id' => $config->id ?? 'from_env',
                'config_api_url' => $config->api_url,
                'ota_platform_code' => $otaPlatformCode,
            ]);
            
            $this->client = new HengdianClient($config, $otaPlatformCode);
            
            Log::info('HengdianService::getClient: HengdianClient 创建成功', [
                'order_id' => $order?->id,
            ]);
        }
        
        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?ResourceConfig
    {
        if (!env('HENGDIAN_API_URL') || !env('HENGDIAN_USERNAME') || !env('HENGDIAN_PASSWORD')) {
            return null;
        }

        $config = new ResourceConfig();
        $config->api_url = env('HENGDIAN_API_URL');
        $config->username = env('HENGDIAN_USERNAME');
        $config->password = env('HENGDIAN_PASSWORD');
        $config->environment = 'production';
        $config->is_active = true;
        $config->extra_config = [
            'sync_mode' => [
                'inventory' => 'manual',
                'price' => 'manual',
                'order' => 'manual',
            ],
            'credentials' => [
                'ctrip' => [
                    'username' => env('HENGDIAN_CTRIP_USERNAME', ''),
                    'password' => env('HENGDIAN_CTRIP_PASSWORD', ''),
                ],
                'meituan' => [
                    'username' => env('HENGDIAN_MEITUAN_USERNAME', ''),
                    'password' => env('HENGDIAN_MEITUAN_PASSWORD', ''),
                ],
                'fliggy' => [
                    'username' => env('HENGDIAN_FLIGGY_USERNAME', ''),
                    'password' => env('HENGDIAN_FLIGGY_PASSWORD', ''),
                ],
            ],
        ];

        return $config;
    }

    /**
     * 价格单位转换：分 → 元
     */
    protected function convertFenToYuan(int $fen): float
    {
        return round($fen / 100, 2);
    }

    /**
     * 价格单位转换：元 → 分
     */
    protected function convertYuanToFen(float $yuan): int
    {
        return (int)round($yuan * 100);
    }

    /**
     * 状态映射：资源方状态 → 系统状态
     */
    protected function mapStatus(string $resourceStatus): ?OrderStatus
    {
        return match($resourceStatus) {
            '1' => OrderStatus::CONFIRMED,      // 预订成功
            '4' => OrderStatus::CANCEL_APPROVED, // 已取消
            '5' => OrderStatus::VERIFIED,       // 已使用
            default => null,
        };
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, string $api, string $error, array $data = []): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => "资源方接口调用失败（{$api}）：{$error}",
            'exception_data' => array_merge([
                'api' => $api,
                'error' => $error,
            ], $data),
            'status' => ExceptionOrderStatus::PENDING,
        ]);
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
        try {
            // 添加详细的调试日志
            Log::info('横店接单开始', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info_exists' => !empty($order->guest_info),
                'guest_info_type' => gettype($order->guest_info),
                'guest_info' => $order->guest_info,
                'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
            ]);
            
            // 构建订单客人信息
            $orderGuestList = [];
            if ($order->guest_info && is_array($order->guest_info)) {
                Log::info('开始处理客人信息', [
                    'order_id' => $order->id,
                    'guest_info_count' => count($order->guest_info),
                    'guest_info_structure' => array_map(function($guest) {
                        return [
                            'keys' => array_keys($guest),
                            'has_name' => isset($guest['name']),
                            'has_cardNo' => isset($guest['cardNo']),
                            'has_idCode' => isset($guest['idCode']),
                            'has_id_code' => isset($guest['id_code']),
                        ];
                    }, $order->guest_info),
                ]);
                
                foreach ($order->guest_info as $index => $guest) {
                    // 兼容多种字段名：cardNo（携程）、idCode、id_code
                    // 注意：数据格式为 [{"cardNo":"530627200211154118","name":"王书桓",...}]
                    $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? '';
                    $name = $guest['name'] ?? '';
                    
                    // 去除首尾空格
                    $idCode = trim((string)$idCode);
                    $name = trim((string)$name);
                    
                    Log::info("处理第 {$index} 个客人", [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'guest_data_keys' => array_keys($guest),
                        'extracted_name' => $name,
                        'extracted_name_length' => strlen($name),
                        'extracted_idCode' => $idCode,
                        'extracted_idCode_length' => strlen($idCode),
                    ]);
                    
                    // 如果姓名或身份证号为空，记录警告但继续处理
                    if (empty($name) || empty($idCode)) {
                        Log::warning('横店订单客人信息不完整', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'guest' => $guest,
                            'name' => $name,
                            'name_empty' => empty($name),
                            'idCode' => $idCode,
                            'idCode_empty' => empty($idCode),
                        ]);
                    }
                    
                    // 只有当姓名和身份证号都不为空时才添加到列表
                    if (!empty($name) && !empty($idCode)) {
                        $orderGuestList[] = [
                            'Name' => $name,
                            'IdCode' => $idCode,
                        ];
                    } else {
                        Log::error('横店订单客人信息缺失，跳过该客人', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'name' => $name,
                            'idCode' => $idCode,
                        ]);
                    }
                }
            }
            
            // 如果客人列表为空，记录错误并直接返回错误（不发送到横店）
            if (empty($orderGuestList)) {
                $errorMessage = '订单客人信息为空，无法发送到景区系统';
                Log::error('横店订单客人信息为空', [
                    'order_id' => $order->id,
                    'guest_info' => $order->guest_info,
                    'guest_info_type' => gettype($order->guest_info),
                    'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
                ]);
                
                // 创建异常订单
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'guest_info' => $order->guest_info,
                    'reason' => '客人信息为空，未发送到景区系统',
                ]);
                
                // 直接返回错误，不发送到横店
                return [
                    'success' => false,
                    'message' => $errorMessage,
                ];
            }

            // 验证 $orderGuestList 中的每个元素
            Log::info('验证客人列表', [
                'order_id' => $order->id,
                'order_guest_list_count' => count($orderGuestList),
                'order_guest_list' => $orderGuestList,
            ]);
            
            foreach ($orderGuestList as $index => $guest) {
                if (empty($guest['Name']) || empty($guest['IdCode'])) {
                    Log::error('横店订单客人信息验证失败', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'name_empty' => empty($guest['Name']),
                        'idCode_empty' => empty($guest['IdCode']),
                    ]);
                } else {
                    Log::info('横店订单客人信息验证通过', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'name' => $guest['Name'],
                        'idCode_length' => strlen($guest['IdCode']),
                    ]);
                }
            }

            // 如果离店日期等于入住日期，根据产品入住天数重新计算
            $checkOutDate = $order->check_out_date;
            if ($checkOutDate->format('Y-m-d') === $order->check_in_date->format('Y-m-d')) {
                $stayDays = $order->product->stay_days ?? 1;
                $checkOutDate = $order->check_in_date->copy()->addDays($stayDays);
                
                Log::warning('横店订单：离店日期等于入住日期，已根据产品入住天数重新计算', [
                    'order_id' => $order->id,
                    'original_check_out_date' => $order->check_out_date->format('Y-m-d'),
                    'calculated_check_out_date' => $checkOutDate->format('Y-m-d'),
                    'stay_days' => $stayDays,
                ]);
            }

            $data = [
                'OtaOrderId' => $order->ota_order_no,
                'PackageId' => $order->product->external_code ?? $order->product->code ?? '', // 优先使用外部编码，如果没有则使用系统内部编码
                'HotelId' => $order->hotel->external_code ?? $order->hotel->code ?? '',
                'RoomType' => $order->roomType->external_code ?? $order->roomType->name ?? '',
                'CheckIn' => $order->check_in_date->format('Y-m-d'),
                'CheckOut' => $checkOutDate->format('Y-m-d'),
                'Amount' => $this->convertYuanToFen($order->total_amount ?? 0),
                'RoomNum' => $order->room_count,
                'PaymentType' => 1, // 预付
                'ContactName' => $order->contact_name ?? '',
                'ContactTel' => $order->contact_phone ?? '',
                'OrderGuests' => [
                    'OrderGuest' => $orderGuestList, // 使用 OrderGuest 作为键，生成正确的 XML 结构
                ],
                'Comment' => $order->remark ?? '',
                'Extensions' => json_encode([]),
            ];

            // 记录发送到景区方系统的数据（包含详细的客人信息）
            Log::info('发送到景区方系统的订单数据', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info' => $order->guest_info,
                'order_guest_list' => $orderGuestList,
                'order_guest_list_count' => count($orderGuestList),
                'request_data' => $data,
            ]);

            $result = $this->getClient($order)->book($data);

            // 如果失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '未知错误');
                
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'result_code' => $errorCode,
                    'response' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方下单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'BookRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '下单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $otaOrderId, ?Order $order = null): array
    {
        try {
            $result = $this->getClient($order)->query([
                'OtaOrderId' => $otaOrderId,
            ]);

            // 如果查询成功，尝试更新订单状态
            if (($result['success'] ?? false) && $order) {
                $status = (string)($result['data']->Status ?? '');
                $mappedStatus = $this->mapStatus($status);
                
                if ($mappedStatus && $order->status !== $mappedStatus) {
                    $order->update(['status' => $mappedStatus]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方查询订单失败', [
                'ota_order_id' => $otaOrderId,
                'error' => $e->getMessage(),
            ]);

            if ($order) {
                $this->createExceptionOrder($order, 'QueryStatusRQ', $e->getMessage());
            }

            return [
                'success' => false,
                'message' => '查询订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 接单（确认订单）
     * 资源方系统：下单预订（BookRQ）成功后即表示接单
     */
    public function confirmOrder(Order $order): array
    {
        Log::info('HengdianService::confirmOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
        ]);
        
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                Log::info('HengdianService::confirmOrder: 订单已有资源方订单号，直接返回成功', [
                    'order_id' => $order->id,
                    'resource_order_no' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 如果没有资源方订单号，调用下单接口（接单）
            Log::info('HengdianService::confirmOrder: 准备调用 book() 方法', [
                'order_id' => $order->id,
            ]);
            
            $result = $this->book($order);
            
            Log::info('HengdianService::confirmOrder: book() 方法返回', [
                'order_id' => $order->id,
                'result_success' => $result['success'] ?? false,
                'result_message' => $result['message'] ?? '',
            ]);

            if ($result['success'] ?? false) {
                // 保存资源方订单号
                $orderId = (string)($result['data']->OrderId ?? '');
                if ($orderId) {
                    $order->update(['resource_order_no' => $orderId]);
                    Log::info('HengdianService::confirmOrder: 已保存资源方订单号', [
                        'order_id' => $order->id,
                        'resource_order_no' => $orderId,
                    ]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'confirmOrder', $e->getMessage());

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 拒单（拒绝订单）
     * 横店系统：通过取消订单接口实现拒单
     */
    public function rejectOrder(Order $order, string $reason): array
    {
        try {
            // 横店系统可能没有专门的拒单接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方拒单', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => '订单已拒绝',
                'data' => ['reason' => $reason],
            ];
        } catch (\Exception $e) {
            Log::error('资源方拒单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 核销订单
     * 横店系统：可能需要调用核销接口（需要查看横店文档）
     */
    public function verifyOrder(Order $order, array $data): array
    {
        try {
            // 横店系统可能没有专门的核销接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方核销订单', [
                'order_id' => $order->id,
                'data' => $data,
            ]);

            return [
                'success' => true,
                'message' => '订单已核销',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('资源方核销失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单是否可以取消
     * 
     * 注意：横店系统可能没有专门的查询是否可以取消的接口
     * 这里通过查询订单状态来判断是否可以取消
     * 如果订单状态为已确认且未使用，则可以取消
     * 
     * @param Order $order 订单
     * @return array [
     *     'can_cancel' => bool,  // 是否可以取消
     *     'message' => string,   // 原因说明
     *     'data' => array        // 额外数据
     * ]
     */
    public function canCancelOrder(Order $order): array
    {
        try {
            // 查询订单状态
            $result = $this->queryOrder($order->ota_order_no, $order);

            if (!($result['success'] ?? false)) {
                // 查询失败，返回可以取消（由后续取消接口判断）
                return [
                    'can_cancel' => true,
                    'message' => '查询订单状态失败，允许尝试取消',
                    'data' => ['query_result' => $result],
                ];
            }

            // 根据订单状态判断是否可以取消
            // 这里需要根据横店系统的实际返回数据结构来判断
            // 假设返回的数据中包含订单状态信息
            $orderData = $result['data'] ?? null;
            
            // 如果订单已使用或已取消，则不能取消
            // 具体判断逻辑需要根据横店系统的实际接口返回数据来调整
            if ($orderData) {
                // 这里需要根据实际返回的数据结构来判断
                // 暂时返回可以取消，由取消接口来最终判断
                return [
                    'can_cancel' => true,
                    'message' => '订单可以取消',
                    'data' => ['order_data' => $orderData],
                ];
            }

            // 默认返回可以取消
            return [
                'can_cancel' => true,
                'message' => '订单可以取消',
                'data' => [],
            ];
        } catch (\Exception $e) {
            Log::error('资源方查询是否可以取消失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // 查询异常，返回可以取消（由后续取消接口判断）
            return [
                'can_cancel' => true,
                'message' => '查询是否可以取消失败，允许尝试取消：' . $e->getMessage(),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * 取消订单
     */
    public function cancelOrder(Order $order, string $reason): array
    {
        try {
            $result = $this->getClient($order)->cancel([
                'OtaOrderId' => $order->ota_order_no,
                'Reason' => $reason,
            ]);

            // 如果取消接口返回失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '订单不可以取消');
                
                // 如果是 -200 错误码，说明订单已过期不能取消
                if ($errorCode === '-200') {
                    $this->createExceptionOrder($order, 'CancelRQ', '订单已过期，不能取消', [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                } else {
                    $this->createExceptionOrder($order, 'CancelRQ', $errorMessage, [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'data' => $result['data'] ?? [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'CancelRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 订阅库存推送
     * 
     * @param array $hotels 酒店列表，格式：[['hotel_id' => '001', 'room_types' => ['标准间', '豪华间']]]
     * @param string $notifyUrl Webhook URL
     * @param bool $unsubscribe 是否取消订阅
     * @return array
     */
    public function subscribeInventory(array $hotels, string $notifyUrl, bool $unsubscribe = false): array
    {
        try {
            // 构建订阅请求数据
            $hotelsData = [];
            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'] ?? '';
                $roomTypes = $hotel['room_types'] ?? [];

                if (empty($hotelId) || empty($roomTypes)) {
                    continue;
                }

                $hotelsData[] = [
                    'HotelId' => $hotelId,
                    'Rooms' => [
                        'RoomType' => $roomTypes, // 索引数组，会生成多个RoomType元素
                    ],
                ];
            }

            $data = [
                'NotifyUrl' => $unsubscribe ? '' : $notifyUrl,
                'IsUnsubscribe' => $unsubscribe ? '1' : '0',
                'Hotels' => $hotelsData,
                'Extensions' => json_encode([]),
            ];

            // 使用默认认证信息（不传订单，所以不传OTA平台代码）
            $config = null;
            if ($this->client) {
                // 如果已有client，使用其配置
                $reflection = new \ReflectionClass($this->client);
                $property = $reflection->getProperty('config');
                $property->setAccessible(true);
                $config = $property->getValue($this->client);
            } else {
                // 否则获取第一个配置
                $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                $config = $provider?->resourceConfigs()->first();
            }

            // 如果还是没有配置，尝试从.env创建临时配置
            if (!$config) {
                $config = $this->createConfigFromEnv();
            }

            if (!$config) {
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }

            $client = new HengdianClient($config); // 不传OTA平台代码，使用默认认证
            $result = $client->subscribeRoomStatus($data);

            if (!($result['success'] ?? false)) {
                Log::error('资源方订阅库存推送失败', [
                    'notify_url' => $notifyUrl,
                    'unsubscribe' => $unsubscribe,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方订阅库存推送异常', [
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '订阅失败：' . $e->getMessage(),
            ];
        }
    }
}


                ]);
                
                $config = $this->createConfigFromEnv();
                Log::info('HengdianService::getClient: 从环境变量创建配置结果', [
                    'order_id' => $order?->id,
                    'config_created' => $config !== null,
                    'config_api_url' => $config?->api_url,
                ]);
            }
            
            if (!$config) {
                Log::error('HengdianService::getClient: 配置不存在', [
                    'order_id' => $order?->id,
                    'error' => '资源方配置不存在，请检查数据库配置或环境变量',
                ]);
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }
            
            // 获取订单的OTA平台代码
            $otaPlatformCode = $order?->otaPlatform?->code?->value ?? null;
            
            Log::info('HengdianService::getClient: 准备创建 HengdianClient', [
                'order_id' => $order?->id,
                'config_id' => $config->id ?? 'from_env',
                'config_api_url' => $config->api_url,
                'ota_platform_code' => $otaPlatformCode,
            ]);
            
            $this->client = new HengdianClient($config, $otaPlatformCode);
            
            Log::info('HengdianService::getClient: HengdianClient 创建成功', [
                'order_id' => $order?->id,
            ]);
        }
        
        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?ResourceConfig
    {
        if (!env('HENGDIAN_API_URL') || !env('HENGDIAN_USERNAME') || !env('HENGDIAN_PASSWORD')) {
            return null;
        }

        $config = new ResourceConfig();
        $config->api_url = env('HENGDIAN_API_URL');
        $config->username = env('HENGDIAN_USERNAME');
        $config->password = env('HENGDIAN_PASSWORD');
        $config->environment = 'production';
        $config->is_active = true;
        $config->extra_config = [
            'sync_mode' => [
                'inventory' => 'manual',
                'price' => 'manual',
                'order' => 'manual',
            ],
            'credentials' => [
                'ctrip' => [
                    'username' => env('HENGDIAN_CTRIP_USERNAME', ''),
                    'password' => env('HENGDIAN_CTRIP_PASSWORD', ''),
                ],
                'meituan' => [
                    'username' => env('HENGDIAN_MEITUAN_USERNAME', ''),
                    'password' => env('HENGDIAN_MEITUAN_PASSWORD', ''),
                ],
                'fliggy' => [
                    'username' => env('HENGDIAN_FLIGGY_USERNAME', ''),
                    'password' => env('HENGDIAN_FLIGGY_PASSWORD', ''),
                ],
            ],
        ];

        return $config;
    }

    /**
     * 价格单位转换：分 → 元
     */
    protected function convertFenToYuan(int $fen): float
    {
        return round($fen / 100, 2);
    }

    /**
     * 价格单位转换：元 → 分
     */
    protected function convertYuanToFen(float $yuan): int
    {
        return (int)round($yuan * 100);
    }

    /**
     * 状态映射：资源方状态 → 系统状态
     */
    protected function mapStatus(string $resourceStatus): ?OrderStatus
    {
        return match($resourceStatus) {
            '1' => OrderStatus::CONFIRMED,      // 预订成功
            '4' => OrderStatus::CANCEL_APPROVED, // 已取消
            '5' => OrderStatus::VERIFIED,       // 已使用
            default => null,
        };
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, string $api, string $error, array $data = []): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => "资源方接口调用失败（{$api}）：{$error}",
            'exception_data' => array_merge([
                'api' => $api,
                'error' => $error,
            ], $data),
            'status' => ExceptionOrderStatus::PENDING,
        ]);
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
        try {
            // 添加详细的调试日志
            Log::info('横店接单开始', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info_exists' => !empty($order->guest_info),
                'guest_info_type' => gettype($order->guest_info),
                'guest_info' => $order->guest_info,
                'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
            ]);
            
            // 构建订单客人信息
            $orderGuestList = [];
            if ($order->guest_info && is_array($order->guest_info)) {
                Log::info('开始处理客人信息', [
                    'order_id' => $order->id,
                    'guest_info_count' => count($order->guest_info),
                    'guest_info_structure' => array_map(function($guest) {
                        return [
                            'keys' => array_keys($guest),
                            'has_name' => isset($guest['name']),
                            'has_cardNo' => isset($guest['cardNo']),
                            'has_idCode' => isset($guest['idCode']),
                            'has_id_code' => isset($guest['id_code']),
                        ];
                    }, $order->guest_info),
                ]);
                
                foreach ($order->guest_info as $index => $guest) {
                    // 兼容多种字段名：cardNo（携程）、idCode、id_code
                    // 注意：数据格式为 [{"cardNo":"530627200211154118","name":"王书桓",...}]
                    $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? '';
                    $name = $guest['name'] ?? '';
                    
                    // 去除首尾空格
                    $idCode = trim((string)$idCode);
                    $name = trim((string)$name);
                    
                    Log::info("处理第 {$index} 个客人", [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'guest_data_keys' => array_keys($guest),
                        'extracted_name' => $name,
                        'extracted_name_length' => strlen($name),
                        'extracted_idCode' => $idCode,
                        'extracted_idCode_length' => strlen($idCode),
                    ]);
                    
                    // 如果姓名或身份证号为空，记录警告但继续处理
                    if (empty($name) || empty($idCode)) {
                        Log::warning('横店订单客人信息不完整', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'guest' => $guest,
                            'name' => $name,
                            'name_empty' => empty($name),
                            'idCode' => $idCode,
                            'idCode_empty' => empty($idCode),
                        ]);
                    }
                    
                    // 只有当姓名和身份证号都不为空时才添加到列表
                    if (!empty($name) && !empty($idCode)) {
                        $orderGuestList[] = [
                            'Name' => $name,
                            'IdCode' => $idCode,
                        ];
                    } else {
                        Log::error('横店订单客人信息缺失，跳过该客人', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'name' => $name,
                            'idCode' => $idCode,
                        ]);
                    }
                }
            }
            
            // 如果客人列表为空，记录错误并直接返回错误（不发送到横店）
            if (empty($orderGuestList)) {
                $errorMessage = '订单客人信息为空，无法发送到景区系统';
                Log::error('横店订单客人信息为空', [
                    'order_id' => $order->id,
                    'guest_info' => $order->guest_info,
                    'guest_info_type' => gettype($order->guest_info),
                    'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
                ]);
                
                // 创建异常订单
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'guest_info' => $order->guest_info,
                    'reason' => '客人信息为空，未发送到景区系统',
                ]);
                
                // 直接返回错误，不发送到横店
                return [
                    'success' => false,
                    'message' => $errorMessage,
                ];
            }

            // 验证 $orderGuestList 中的每个元素
            Log::info('验证客人列表', [
                'order_id' => $order->id,
                'order_guest_list_count' => count($orderGuestList),
                'order_guest_list' => $orderGuestList,
            ]);
            
            foreach ($orderGuestList as $index => $guest) {
                if (empty($guest['Name']) || empty($guest['IdCode'])) {
                    Log::error('横店订单客人信息验证失败', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'name_empty' => empty($guest['Name']),
                        'idCode_empty' => empty($guest['IdCode']),
                    ]);
                } else {
                    Log::info('横店订单客人信息验证通过', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'name' => $guest['Name'],
                        'idCode_length' => strlen($guest['IdCode']),
                    ]);
                }
            }

            // 如果离店日期等于入住日期，根据产品入住天数重新计算
            $checkOutDate = $order->check_out_date;
            if ($checkOutDate->format('Y-m-d') === $order->check_in_date->format('Y-m-d')) {
                $stayDays = $order->product->stay_days ?? 1;
                $checkOutDate = $order->check_in_date->copy()->addDays($stayDays);
                
                Log::warning('横店订单：离店日期等于入住日期，已根据产品入住天数重新计算', [
                    'order_id' => $order->id,
                    'original_check_out_date' => $order->check_out_date->format('Y-m-d'),
                    'calculated_check_out_date' => $checkOutDate->format('Y-m-d'),
                    'stay_days' => $stayDays,
                ]);
            }

            $data = [
                'OtaOrderId' => $order->ota_order_no,
                'PackageId' => $order->product->external_code ?? $order->product->code ?? '', // 优先使用外部编码，如果没有则使用系统内部编码
                'HotelId' => $order->hotel->external_code ?? $order->hotel->code ?? '',
                'RoomType' => $order->roomType->external_code ?? $order->roomType->name ?? '',
                'CheckIn' => $order->check_in_date->format('Y-m-d'),
                'CheckOut' => $checkOutDate->format('Y-m-d'),
                'Amount' => $this->convertYuanToFen($order->total_amount ?? 0),
                'RoomNum' => $order->room_count,
                'PaymentType' => 1, // 预付
                'ContactName' => $order->contact_name ?? '',
                'ContactTel' => $order->contact_phone ?? '',
                'OrderGuests' => [
                    'OrderGuest' => $orderGuestList, // 使用 OrderGuest 作为键，生成正确的 XML 结构
                ],
                'Comment' => $order->remark ?? '',
                'Extensions' => json_encode([]),
            ];

            // 记录发送到景区方系统的数据（包含详细的客人信息）
            Log::info('发送到景区方系统的订单数据', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info' => $order->guest_info,
                'order_guest_list' => $orderGuestList,
                'order_guest_list_count' => count($orderGuestList),
                'request_data' => $data,
            ]);

            $result = $this->getClient($order)->book($data);

            // 如果失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '未知错误');
                
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'result_code' => $errorCode,
                    'response' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方下单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'BookRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '下单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $otaOrderId, ?Order $order = null): array
    {
        try {
            $result = $this->getClient($order)->query([
                'OtaOrderId' => $otaOrderId,
            ]);

            // 如果查询成功，尝试更新订单状态
            if (($result['success'] ?? false) && $order) {
                $status = (string)($result['data']->Status ?? '');
                $mappedStatus = $this->mapStatus($status);
                
                if ($mappedStatus && $order->status !== $mappedStatus) {
                    $order->update(['status' => $mappedStatus]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方查询订单失败', [
                'ota_order_id' => $otaOrderId,
                'error' => $e->getMessage(),
            ]);

            if ($order) {
                $this->createExceptionOrder($order, 'QueryStatusRQ', $e->getMessage());
            }

            return [
                'success' => false,
                'message' => '查询订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 接单（确认订单）
     * 资源方系统：下单预订（BookRQ）成功后即表示接单
     */
    public function confirmOrder(Order $order): array
    {
        Log::info('HengdianService::confirmOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
        ]);
        
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                Log::info('HengdianService::confirmOrder: 订单已有资源方订单号，直接返回成功', [
                    'order_id' => $order->id,
                    'resource_order_no' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 如果没有资源方订单号，调用下单接口（接单）
            Log::info('HengdianService::confirmOrder: 准备调用 book() 方法', [
                'order_id' => $order->id,
            ]);
            
            $result = $this->book($order);
            
            Log::info('HengdianService::confirmOrder: book() 方法返回', [
                'order_id' => $order->id,
                'result_success' => $result['success'] ?? false,
                'result_message' => $result['message'] ?? '',
            ]);

            if ($result['success'] ?? false) {
                // 保存资源方订单号
                $orderId = (string)($result['data']->OrderId ?? '');
                if ($orderId) {
                    $order->update(['resource_order_no' => $orderId]);
                    Log::info('HengdianService::confirmOrder: 已保存资源方订单号', [
                        'order_id' => $order->id,
                        'resource_order_no' => $orderId,
                    ]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'confirmOrder', $e->getMessage());

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 拒单（拒绝订单）
     * 横店系统：通过取消订单接口实现拒单
     */
    public function rejectOrder(Order $order, string $reason): array
    {
        try {
            // 横店系统可能没有专门的拒单接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方拒单', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => '订单已拒绝',
                'data' => ['reason' => $reason],
            ];
        } catch (\Exception $e) {
            Log::error('资源方拒单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 核销订单
     * 横店系统：可能需要调用核销接口（需要查看横店文档）
     */
    public function verifyOrder(Order $order, array $data): array
    {
        try {
            // 横店系统可能没有专门的核销接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方核销订单', [
                'order_id' => $order->id,
                'data' => $data,
            ]);

            return [
                'success' => true,
                'message' => '订单已核销',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('资源方核销失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单是否可以取消
     * 
     * 注意：横店系统可能没有专门的查询是否可以取消的接口
     * 这里通过查询订单状态来判断是否可以取消
     * 如果订单状态为已确认且未使用，则可以取消
     * 
     * @param Order $order 订单
     * @return array [
     *     'can_cancel' => bool,  // 是否可以取消
     *     'message' => string,   // 原因说明
     *     'data' => array        // 额外数据
     * ]
     */
    public function canCancelOrder(Order $order): array
    {
        try {
            // 查询订单状态
            $result = $this->queryOrder($order->ota_order_no, $order);

            if (!($result['success'] ?? false)) {
                // 查询失败，返回可以取消（由后续取消接口判断）
                return [
                    'can_cancel' => true,
                    'message' => '查询订单状态失败，允许尝试取消',
                    'data' => ['query_result' => $result],
                ];
            }

            // 根据订单状态判断是否可以取消
            // 这里需要根据横店系统的实际返回数据结构来判断
            // 假设返回的数据中包含订单状态信息
            $orderData = $result['data'] ?? null;
            
            // 如果订单已使用或已取消，则不能取消
            // 具体判断逻辑需要根据横店系统的实际接口返回数据来调整
            if ($orderData) {
                // 这里需要根据实际返回的数据结构来判断
                // 暂时返回可以取消，由取消接口来最终判断
                return [
                    'can_cancel' => true,
                    'message' => '订单可以取消',
                    'data' => ['order_data' => $orderData],
                ];
            }

            // 默认返回可以取消
            return [
                'can_cancel' => true,
                'message' => '订单可以取消',
                'data' => [],
            ];
        } catch (\Exception $e) {
            Log::error('资源方查询是否可以取消失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // 查询异常，返回可以取消（由后续取消接口判断）
            return [
                'can_cancel' => true,
                'message' => '查询是否可以取消失败，允许尝试取消：' . $e->getMessage(),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * 取消订单
     */
    public function cancelOrder(Order $order, string $reason): array
    {
        try {
            $result = $this->getClient($order)->cancel([
                'OtaOrderId' => $order->ota_order_no,
                'Reason' => $reason,
            ]);

            // 如果取消接口返回失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '订单不可以取消');
                
                // 如果是 -200 错误码，说明订单已过期不能取消
                if ($errorCode === '-200') {
                    $this->createExceptionOrder($order, 'CancelRQ', '订单已过期，不能取消', [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                } else {
                    $this->createExceptionOrder($order, 'CancelRQ', $errorMessage, [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'data' => $result['data'] ?? [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'CancelRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 订阅库存推送
     * 
     * @param array $hotels 酒店列表，格式：[['hotel_id' => '001', 'room_types' => ['标准间', '豪华间']]]
     * @param string $notifyUrl Webhook URL
     * @param bool $unsubscribe 是否取消订阅
     * @return array
     */
    public function subscribeInventory(array $hotels, string $notifyUrl, bool $unsubscribe = false): array
    {
        try {
            // 构建订阅请求数据
            $hotelsData = [];
            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'] ?? '';
                $roomTypes = $hotel['room_types'] ?? [];

                if (empty($hotelId) || empty($roomTypes)) {
                    continue;
                }

                $hotelsData[] = [
                    'HotelId' => $hotelId,
                    'Rooms' => [
                        'RoomType' => $roomTypes, // 索引数组，会生成多个RoomType元素
                    ],
                ];
            }

            $data = [
                'NotifyUrl' => $unsubscribe ? '' : $notifyUrl,
                'IsUnsubscribe' => $unsubscribe ? '1' : '0',
                'Hotels' => $hotelsData,
                'Extensions' => json_encode([]),
            ];

            // 使用默认认证信息（不传订单，所以不传OTA平台代码）
            $config = null;
            if ($this->client) {
                // 如果已有client，使用其配置
                $reflection = new \ReflectionClass($this->client);
                $property = $reflection->getProperty('config');
                $property->setAccessible(true);
                $config = $property->getValue($this->client);
            } else {
                // 否则获取第一个配置
                $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                $config = $provider?->resourceConfigs()->first();
            }

            // 如果还是没有配置，尝试从.env创建临时配置
            if (!$config) {
                $config = $this->createConfigFromEnv();
            }

            if (!$config) {
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }

            $client = new HengdianClient($config); // 不传OTA平台代码，使用默认认证
            $result = $client->subscribeRoomStatus($data);

            if (!($result['success'] ?? false)) {
                Log::error('资源方订阅库存推送失败', [
                    'notify_url' => $notifyUrl,
                    'unsubscribe' => $unsubscribe,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方订阅库存推送异常', [
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '订阅失败：' . $e->getMessage(),
            ];
        }
    }
}


                ]);
                
                $config = $this->createConfigFromEnv();
                Log::info('HengdianService::getClient: 从环境变量创建配置结果', [
                    'order_id' => $order?->id,
                    'config_created' => $config !== null,
                    'config_api_url' => $config?->api_url,
                ]);
            }
            
            if (!$config) {
                Log::error('HengdianService::getClient: 配置不存在', [
                    'order_id' => $order?->id,
                    'error' => '资源方配置不存在，请检查数据库配置或环境变量',
                ]);
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }
            
            // 获取订单的OTA平台代码
            $otaPlatformCode = $order?->otaPlatform?->code?->value ?? null;
            
            Log::info('HengdianService::getClient: 准备创建 HengdianClient', [
                'order_id' => $order?->id,
                'config_id' => $config->id ?? 'from_env',
                'config_api_url' => $config->api_url,
                'ota_platform_code' => $otaPlatformCode,
            ]);
            
            $this->client = new HengdianClient($config, $otaPlatformCode);
            
            Log::info('HengdianService::getClient: HengdianClient 创建成功', [
                'order_id' => $order?->id,
            ]);
        }
        
        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?ResourceConfig
    {
        if (!env('HENGDIAN_API_URL') || !env('HENGDIAN_USERNAME') || !env('HENGDIAN_PASSWORD')) {
            return null;
        }

        $config = new ResourceConfig();
        $config->api_url = env('HENGDIAN_API_URL');
        $config->username = env('HENGDIAN_USERNAME');
        $config->password = env('HENGDIAN_PASSWORD');
        $config->environment = 'production';
        $config->is_active = true;
        $config->extra_config = [
            'sync_mode' => [
                'inventory' => 'manual',
                'price' => 'manual',
                'order' => 'manual',
            ],
            'credentials' => [
                'ctrip' => [
                    'username' => env('HENGDIAN_CTRIP_USERNAME', ''),
                    'password' => env('HENGDIAN_CTRIP_PASSWORD', ''),
                ],
                'meituan' => [
                    'username' => env('HENGDIAN_MEITUAN_USERNAME', ''),
                    'password' => env('HENGDIAN_MEITUAN_PASSWORD', ''),
                ],
                'fliggy' => [
                    'username' => env('HENGDIAN_FLIGGY_USERNAME', ''),
                    'password' => env('HENGDIAN_FLIGGY_PASSWORD', ''),
                ],
            ],
        ];

        return $config;
    }

    /**
     * 价格单位转换：分 → 元
     */
    protected function convertFenToYuan(int $fen): float
    {
        return round($fen / 100, 2);
    }

    /**
     * 价格单位转换：元 → 分
     */
    protected function convertYuanToFen(float $yuan): int
    {
        return (int)round($yuan * 100);
    }

    /**
     * 状态映射：资源方状态 → 系统状态
     */
    protected function mapStatus(string $resourceStatus): ?OrderStatus
    {
        return match($resourceStatus) {
            '1' => OrderStatus::CONFIRMED,      // 预订成功
            '4' => OrderStatus::CANCEL_APPROVED, // 已取消
            '5' => OrderStatus::VERIFIED,       // 已使用
            default => null,
        };
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, string $api, string $error, array $data = []): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => "资源方接口调用失败（{$api}）：{$error}",
            'exception_data' => array_merge([
                'api' => $api,
                'error' => $error,
            ], $data),
            'status' => ExceptionOrderStatus::PENDING,
        ]);
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
        try {
            // 添加详细的调试日志
            Log::info('横店接单开始', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info_exists' => !empty($order->guest_info),
                'guest_info_type' => gettype($order->guest_info),
                'guest_info' => $order->guest_info,
                'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
            ]);
            
            // 构建订单客人信息
            $orderGuestList = [];
            if ($order->guest_info && is_array($order->guest_info)) {
                Log::info('开始处理客人信息', [
                    'order_id' => $order->id,
                    'guest_info_count' => count($order->guest_info),
                    'guest_info_structure' => array_map(function($guest) {
                        return [
                            'keys' => array_keys($guest),
                            'has_name' => isset($guest['name']),
                            'has_cardNo' => isset($guest['cardNo']),
                            'has_idCode' => isset($guest['idCode']),
                            'has_id_code' => isset($guest['id_code']),
                        ];
                    }, $order->guest_info),
                ]);
                
                foreach ($order->guest_info as $index => $guest) {
                    // 兼容多种字段名：cardNo（携程）、idCode、id_code
                    // 注意：数据格式为 [{"cardNo":"530627200211154118","name":"王书桓",...}]
                    $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? '';
                    $name = $guest['name'] ?? '';
                    
                    // 去除首尾空格
                    $idCode = trim((string)$idCode);
                    $name = trim((string)$name);
                    
                    Log::info("处理第 {$index} 个客人", [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'guest_data_keys' => array_keys($guest),
                        'extracted_name' => $name,
                        'extracted_name_length' => strlen($name),
                        'extracted_idCode' => $idCode,
                        'extracted_idCode_length' => strlen($idCode),
                    ]);
                    
                    // 如果姓名或身份证号为空，记录警告但继续处理
                    if (empty($name) || empty($idCode)) {
                        Log::warning('横店订单客人信息不完整', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'guest' => $guest,
                            'name' => $name,
                            'name_empty' => empty($name),
                            'idCode' => $idCode,
                            'idCode_empty' => empty($idCode),
                        ]);
                    }
                    
                    // 只有当姓名和身份证号都不为空时才添加到列表
                    if (!empty($name) && !empty($idCode)) {
                        $orderGuestList[] = [
                            'Name' => $name,
                            'IdCode' => $idCode,
                        ];
                    } else {
                        Log::error('横店订单客人信息缺失，跳过该客人', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'name' => $name,
                            'idCode' => $idCode,
                        ]);
                    }
                }
            }
            
            // 如果客人列表为空，记录错误并直接返回错误（不发送到横店）
            if (empty($orderGuestList)) {
                $errorMessage = '订单客人信息为空，无法发送到景区系统';
                Log::error('横店订单客人信息为空', [
                    'order_id' => $order->id,
                    'guest_info' => $order->guest_info,
                    'guest_info_type' => gettype($order->guest_info),
                    'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
                ]);
                
                // 创建异常订单
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'guest_info' => $order->guest_info,
                    'reason' => '客人信息为空，未发送到景区系统',
                ]);
                
                // 直接返回错误，不发送到横店
                return [
                    'success' => false,
                    'message' => $errorMessage,
                ];
            }

            // 验证 $orderGuestList 中的每个元素
            Log::info('验证客人列表', [
                'order_id' => $order->id,
                'order_guest_list_count' => count($orderGuestList),
                'order_guest_list' => $orderGuestList,
            ]);
            
            foreach ($orderGuestList as $index => $guest) {
                if (empty($guest['Name']) || empty($guest['IdCode'])) {
                    Log::error('横店订单客人信息验证失败', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'name_empty' => empty($guest['Name']),
                        'idCode_empty' => empty($guest['IdCode']),
                    ]);
                } else {
                    Log::info('横店订单客人信息验证通过', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'name' => $guest['Name'],
                        'idCode_length' => strlen($guest['IdCode']),
                    ]);
                }
            }

            // 如果离店日期等于入住日期，根据产品入住天数重新计算
            $checkOutDate = $order->check_out_date;
            if ($checkOutDate->format('Y-m-d') === $order->check_in_date->format('Y-m-d')) {
                $stayDays = $order->product->stay_days ?? 1;
                $checkOutDate = $order->check_in_date->copy()->addDays($stayDays);
                
                Log::warning('横店订单：离店日期等于入住日期，已根据产品入住天数重新计算', [
                    'order_id' => $order->id,
                    'original_check_out_date' => $order->check_out_date->format('Y-m-d'),
                    'calculated_check_out_date' => $checkOutDate->format('Y-m-d'),
                    'stay_days' => $stayDays,
                ]);
            }

            $data = [
                'OtaOrderId' => $order->ota_order_no,
                'PackageId' => $order->product->external_code ?? $order->product->code ?? '', // 优先使用外部编码，如果没有则使用系统内部编码
                'HotelId' => $order->hotel->external_code ?? $order->hotel->code ?? '',
                'RoomType' => $order->roomType->external_code ?? $order->roomType->name ?? '',
                'CheckIn' => $order->check_in_date->format('Y-m-d'),
                'CheckOut' => $checkOutDate->format('Y-m-d'),
                'Amount' => $this->convertYuanToFen($order->total_amount ?? 0),
                'RoomNum' => $order->room_count,
                'PaymentType' => 1, // 预付
                'ContactName' => $order->contact_name ?? '',
                'ContactTel' => $order->contact_phone ?? '',
                'OrderGuests' => [
                    'OrderGuest' => $orderGuestList, // 使用 OrderGuest 作为键，生成正确的 XML 结构
                ],
                'Comment' => $order->remark ?? '',
                'Extensions' => json_encode([]),
            ];

            // 记录发送到景区方系统的数据（包含详细的客人信息）
            Log::info('发送到景区方系统的订单数据', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info' => $order->guest_info,
                'order_guest_list' => $orderGuestList,
                'order_guest_list_count' => count($orderGuestList),
                'request_data' => $data,
            ]);

            $result = $this->getClient($order)->book($data);

            // 如果失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '未知错误');
                
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'result_code' => $errorCode,
                    'response' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方下单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'BookRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '下单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $otaOrderId, ?Order $order = null): array
    {
        try {
            $result = $this->getClient($order)->query([
                'OtaOrderId' => $otaOrderId,
            ]);

            // 如果查询成功，尝试更新订单状态
            if (($result['success'] ?? false) && $order) {
                $status = (string)($result['data']->Status ?? '');
                $mappedStatus = $this->mapStatus($status);
                
                if ($mappedStatus && $order->status !== $mappedStatus) {
                    $order->update(['status' => $mappedStatus]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方查询订单失败', [
                'ota_order_id' => $otaOrderId,
                'error' => $e->getMessage(),
            ]);

            if ($order) {
                $this->createExceptionOrder($order, 'QueryStatusRQ', $e->getMessage());
            }

            return [
                'success' => false,
                'message' => '查询订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 接单（确认订单）
     * 资源方系统：下单预订（BookRQ）成功后即表示接单
     */
    public function confirmOrder(Order $order): array
    {
        Log::info('HengdianService::confirmOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
        ]);
        
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                Log::info('HengdianService::confirmOrder: 订单已有资源方订单号，直接返回成功', [
                    'order_id' => $order->id,
                    'resource_order_no' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 如果没有资源方订单号，调用下单接口（接单）
            Log::info('HengdianService::confirmOrder: 准备调用 book() 方法', [
                'order_id' => $order->id,
            ]);
            
            $result = $this->book($order);
            
            Log::info('HengdianService::confirmOrder: book() 方法返回', [
                'order_id' => $order->id,
                'result_success' => $result['success'] ?? false,
                'result_message' => $result['message'] ?? '',
            ]);

            if ($result['success'] ?? false) {
                // 保存资源方订单号
                $orderId = (string)($result['data']->OrderId ?? '');
                if ($orderId) {
                    $order->update(['resource_order_no' => $orderId]);
                    Log::info('HengdianService::confirmOrder: 已保存资源方订单号', [
                        'order_id' => $order->id,
                        'resource_order_no' => $orderId,
                    ]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'confirmOrder', $e->getMessage());

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 拒单（拒绝订单）
     * 横店系统：通过取消订单接口实现拒单
     */
    public function rejectOrder(Order $order, string $reason): array
    {
        try {
            // 横店系统可能没有专门的拒单接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方拒单', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => '订单已拒绝',
                'data' => ['reason' => $reason],
            ];
        } catch (\Exception $e) {
            Log::error('资源方拒单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 核销订单
     * 横店系统：可能需要调用核销接口（需要查看横店文档）
     */
    public function verifyOrder(Order $order, array $data): array
    {
        try {
            // 横店系统可能没有专门的核销接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方核销订单', [
                'order_id' => $order->id,
                'data' => $data,
            ]);

            return [
                'success' => true,
                'message' => '订单已核销',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('资源方核销失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单是否可以取消
     * 
     * 注意：横店系统可能没有专门的查询是否可以取消的接口
     * 这里通过查询订单状态来判断是否可以取消
     * 如果订单状态为已确认且未使用，则可以取消
     * 
     * @param Order $order 订单
     * @return array [
     *     'can_cancel' => bool,  // 是否可以取消
     *     'message' => string,   // 原因说明
     *     'data' => array        // 额外数据
     * ]
     */
    public function canCancelOrder(Order $order): array
    {
        try {
            // 查询订单状态
            $result = $this->queryOrder($order->ota_order_no, $order);

            if (!($result['success'] ?? false)) {
                // 查询失败，返回可以取消（由后续取消接口判断）
                return [
                    'can_cancel' => true,
                    'message' => '查询订单状态失败，允许尝试取消',
                    'data' => ['query_result' => $result],
                ];
            }

            // 根据订单状态判断是否可以取消
            // 这里需要根据横店系统的实际返回数据结构来判断
            // 假设返回的数据中包含订单状态信息
            $orderData = $result['data'] ?? null;
            
            // 如果订单已使用或已取消，则不能取消
            // 具体判断逻辑需要根据横店系统的实际接口返回数据来调整
            if ($orderData) {
                // 这里需要根据实际返回的数据结构来判断
                // 暂时返回可以取消，由取消接口来最终判断
                return [
                    'can_cancel' => true,
                    'message' => '订单可以取消',
                    'data' => ['order_data' => $orderData],
                ];
            }

            // 默认返回可以取消
            return [
                'can_cancel' => true,
                'message' => '订单可以取消',
                'data' => [],
            ];
        } catch (\Exception $e) {
            Log::error('资源方查询是否可以取消失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // 查询异常，返回可以取消（由后续取消接口判断）
            return [
                'can_cancel' => true,
                'message' => '查询是否可以取消失败，允许尝试取消：' . $e->getMessage(),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * 取消订单
     */
    public function cancelOrder(Order $order, string $reason): array
    {
        try {
            $result = $this->getClient($order)->cancel([
                'OtaOrderId' => $order->ota_order_no,
                'Reason' => $reason,
            ]);

            // 如果取消接口返回失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '订单不可以取消');
                
                // 如果是 -200 错误码，说明订单已过期不能取消
                if ($errorCode === '-200') {
                    $this->createExceptionOrder($order, 'CancelRQ', '订单已过期，不能取消', [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                } else {
                    $this->createExceptionOrder($order, 'CancelRQ', $errorMessage, [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'data' => $result['data'] ?? [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'CancelRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 订阅库存推送
     * 
     * @param array $hotels 酒店列表，格式：[['hotel_id' => '001', 'room_types' => ['标准间', '豪华间']]]
     * @param string $notifyUrl Webhook URL
     * @param bool $unsubscribe 是否取消订阅
     * @return array
     */
    public function subscribeInventory(array $hotels, string $notifyUrl, bool $unsubscribe = false): array
    {
        try {
            // 构建订阅请求数据
            $hotelsData = [];
            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'] ?? '';
                $roomTypes = $hotel['room_types'] ?? [];

                if (empty($hotelId) || empty($roomTypes)) {
                    continue;
                }

                $hotelsData[] = [
                    'HotelId' => $hotelId,
                    'Rooms' => [
                        'RoomType' => $roomTypes, // 索引数组，会生成多个RoomType元素
                    ],
                ];
            }

            $data = [
                'NotifyUrl' => $unsubscribe ? '' : $notifyUrl,
                'IsUnsubscribe' => $unsubscribe ? '1' : '0',
                'Hotels' => $hotelsData,
                'Extensions' => json_encode([]),
            ];

            // 使用默认认证信息（不传订单，所以不传OTA平台代码）
            $config = null;
            if ($this->client) {
                // 如果已有client，使用其配置
                $reflection = new \ReflectionClass($this->client);
                $property = $reflection->getProperty('config');
                $property->setAccessible(true);
                $config = $property->getValue($this->client);
            } else {
                // 否则获取第一个配置
                $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                $config = $provider?->resourceConfigs()->first();
            }

            // 如果还是没有配置，尝试从.env创建临时配置
            if (!$config) {
                $config = $this->createConfigFromEnv();
            }

            if (!$config) {
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }

            $client = new HengdianClient($config); // 不传OTA平台代码，使用默认认证
            $result = $client->subscribeRoomStatus($data);

            if (!($result['success'] ?? false)) {
                Log::error('资源方订阅库存推送失败', [
                    'notify_url' => $notifyUrl,
                    'unsubscribe' => $unsubscribe,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方订阅库存推送异常', [
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '订阅失败：' . $e->getMessage(),
            ];
        }
    }
}


                ]);
                
                $config = $this->createConfigFromEnv();
                Log::info('HengdianService::getClient: 从环境变量创建配置结果', [
                    'order_id' => $order?->id,
                    'config_created' => $config !== null,
                    'config_api_url' => $config?->api_url,
                ]);
            }
            
            if (!$config) {
                Log::error('HengdianService::getClient: 配置不存在', [
                    'order_id' => $order?->id,
                    'error' => '资源方配置不存在，请检查数据库配置或环境变量',
                ]);
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }
            
            // 获取订单的OTA平台代码
            $otaPlatformCode = $order?->otaPlatform?->code?->value ?? null;
            
            Log::info('HengdianService::getClient: 准备创建 HengdianClient', [
                'order_id' => $order?->id,
                'config_id' => $config->id ?? 'from_env',
                'config_api_url' => $config->api_url,
                'ota_platform_code' => $otaPlatformCode,
            ]);
            
            $this->client = new HengdianClient($config, $otaPlatformCode);
            
            Log::info('HengdianService::getClient: HengdianClient 创建成功', [
                'order_id' => $order?->id,
            ]);
        }
        
        return $this->client;
    }

    /**
     * 从环境变量创建配置对象
     */
    protected function createConfigFromEnv(): ?ResourceConfig
    {
        if (!env('HENGDIAN_API_URL') || !env('HENGDIAN_USERNAME') || !env('HENGDIAN_PASSWORD')) {
            return null;
        }

        $config = new ResourceConfig();
        $config->api_url = env('HENGDIAN_API_URL');
        $config->username = env('HENGDIAN_USERNAME');
        $config->password = env('HENGDIAN_PASSWORD');
        $config->environment = 'production';
        $config->is_active = true;
        $config->extra_config = [
            'sync_mode' => [
                'inventory' => 'manual',
                'price' => 'manual',
                'order' => 'manual',
            ],
            'credentials' => [
                'ctrip' => [
                    'username' => env('HENGDIAN_CTRIP_USERNAME', ''),
                    'password' => env('HENGDIAN_CTRIP_PASSWORD', ''),
                ],
                'meituan' => [
                    'username' => env('HENGDIAN_MEITUAN_USERNAME', ''),
                    'password' => env('HENGDIAN_MEITUAN_PASSWORD', ''),
                ],
                'fliggy' => [
                    'username' => env('HENGDIAN_FLIGGY_USERNAME', ''),
                    'password' => env('HENGDIAN_FLIGGY_PASSWORD', ''),
                ],
            ],
        ];

        return $config;
    }

    /**
     * 价格单位转换：分 → 元
     */
    protected function convertFenToYuan(int $fen): float
    {
        return round($fen / 100, 2);
    }

    /**
     * 价格单位转换：元 → 分
     */
    protected function convertYuanToFen(float $yuan): int
    {
        return (int)round($yuan * 100);
    }

    /**
     * 状态映射：资源方状态 → 系统状态
     */
    protected function mapStatus(string $resourceStatus): ?OrderStatus
    {
        return match($resourceStatus) {
            '1' => OrderStatus::CONFIRMED,      // 预订成功
            '4' => OrderStatus::CANCEL_APPROVED, // 已取消
            '5' => OrderStatus::VERIFIED,       // 已使用
            default => null,
        };
    }

    /**
     * 创建异常订单
     */
    protected function createExceptionOrder(Order $order, string $api, string $error, array $data = []): void
    {
        ExceptionOrder::create([
            'order_id' => $order->id,
            'exception_type' => ExceptionOrderType::API_ERROR,
            'exception_message' => "资源方接口调用失败（{$api}）：{$error}",
            'exception_data' => array_merge([
                'api' => $api,
                'error' => $error,
            ], $data),
            'status' => ExceptionOrderStatus::PENDING,
        ]);
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
        try {
            // 添加详细的调试日志
            Log::info('横店接单开始', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info_exists' => !empty($order->guest_info),
                'guest_info_type' => gettype($order->guest_info),
                'guest_info' => $order->guest_info,
                'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
            ]);
            
            // 构建订单客人信息
            $orderGuestList = [];
            if ($order->guest_info && is_array($order->guest_info)) {
                Log::info('开始处理客人信息', [
                    'order_id' => $order->id,
                    'guest_info_count' => count($order->guest_info),
                    'guest_info_structure' => array_map(function($guest) {
                        return [
                            'keys' => array_keys($guest),
                            'has_name' => isset($guest['name']),
                            'has_cardNo' => isset($guest['cardNo']),
                            'has_idCode' => isset($guest['idCode']),
                            'has_id_code' => isset($guest['id_code']),
                        ];
                    }, $order->guest_info),
                ]);
                
                foreach ($order->guest_info as $index => $guest) {
                    // 兼容多种字段名：cardNo（携程）、idCode、id_code
                    // 注意：数据格式为 [{"cardNo":"530627200211154118","name":"王书桓",...}]
                    $idCode = $guest['cardNo'] ?? $guest['idCode'] ?? $guest['id_code'] ?? '';
                    $name = $guest['name'] ?? '';
                    
                    // 去除首尾空格
                    $idCode = trim((string)$idCode);
                    $name = trim((string)$name);
                    
                    Log::info("处理第 {$index} 个客人", [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'guest_data_keys' => array_keys($guest),
                        'extracted_name' => $name,
                        'extracted_name_length' => strlen($name),
                        'extracted_idCode' => $idCode,
                        'extracted_idCode_length' => strlen($idCode),
                    ]);
                    
                    // 如果姓名或身份证号为空，记录警告但继续处理
                    if (empty($name) || empty($idCode)) {
                        Log::warning('横店订单客人信息不完整', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'guest' => $guest,
                            'name' => $name,
                            'name_empty' => empty($name),
                            'idCode' => $idCode,
                            'idCode_empty' => empty($idCode),
                        ]);
                    }
                    
                    // 只有当姓名和身份证号都不为空时才添加到列表
                    if (!empty($name) && !empty($idCode)) {
                        $orderGuestList[] = [
                            'Name' => $name,
                            'IdCode' => $idCode,
                        ];
                    } else {
                        Log::error('横店订单客人信息缺失，跳过该客人', [
                            'order_id' => $order->id,
                            'guest_index' => $index,
                            'name' => $name,
                            'idCode' => $idCode,
                        ]);
                    }
                }
            }
            
            // 如果客人列表为空，记录错误并直接返回错误（不发送到横店）
            if (empty($orderGuestList)) {
                $errorMessage = '订单客人信息为空，无法发送到景区系统';
                Log::error('横店订单客人信息为空', [
                    'order_id' => $order->id,
                    'guest_info' => $order->guest_info,
                    'guest_info_type' => gettype($order->guest_info),
                    'guest_info_count' => is_array($order->guest_info) ? count($order->guest_info) : 0,
                ]);
                
                // 创建异常订单
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'guest_info' => $order->guest_info,
                    'reason' => '客人信息为空，未发送到景区系统',
                ]);
                
                // 直接返回错误，不发送到横店
                return [
                    'success' => false,
                    'message' => $errorMessage,
                ];
            }

            // 验证 $orderGuestList 中的每个元素
            Log::info('验证客人列表', [
                'order_id' => $order->id,
                'order_guest_list_count' => count($orderGuestList),
                'order_guest_list' => $orderGuestList,
            ]);
            
            foreach ($orderGuestList as $index => $guest) {
                if (empty($guest['Name']) || empty($guest['IdCode'])) {
                    Log::error('横店订单客人信息验证失败', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'guest_data' => $guest,
                        'name_empty' => empty($guest['Name']),
                        'idCode_empty' => empty($guest['IdCode']),
                    ]);
                } else {
                    Log::info('横店订单客人信息验证通过', [
                        'order_id' => $order->id,
                        'guest_index' => $index,
                        'name' => $guest['Name'],
                        'idCode_length' => strlen($guest['IdCode']),
                    ]);
                }
            }

            // 如果离店日期等于入住日期，根据产品入住天数重新计算
            $checkOutDate = $order->check_out_date;
            if ($checkOutDate->format('Y-m-d') === $order->check_in_date->format('Y-m-d')) {
                $stayDays = $order->product->stay_days ?? 1;
                $checkOutDate = $order->check_in_date->copy()->addDays($stayDays);
                
                Log::warning('横店订单：离店日期等于入住日期，已根据产品入住天数重新计算', [
                    'order_id' => $order->id,
                    'original_check_out_date' => $order->check_out_date->format('Y-m-d'),
                    'calculated_check_out_date' => $checkOutDate->format('Y-m-d'),
                    'stay_days' => $stayDays,
                ]);
            }

            $data = [
                'OtaOrderId' => $order->ota_order_no,
                'PackageId' => $order->product->external_code ?? $order->product->code ?? '', // 优先使用外部编码，如果没有则使用系统内部编码
                'HotelId' => $order->hotel->external_code ?? $order->hotel->code ?? '',
                'RoomType' => $order->roomType->external_code ?? $order->roomType->name ?? '',
                'CheckIn' => $order->check_in_date->format('Y-m-d'),
                'CheckOut' => $checkOutDate->format('Y-m-d'),
                'Amount' => $this->convertYuanToFen($order->total_amount ?? 0),
                'RoomNum' => $order->room_count,
                'PaymentType' => 1, // 预付
                'ContactName' => $order->contact_name ?? '',
                'ContactTel' => $order->contact_phone ?? '',
                'OrderGuests' => [
                    'OrderGuest' => $orderGuestList, // 使用 OrderGuest 作为键，生成正确的 XML 结构
                ],
                'Comment' => $order->remark ?? '',
                'Extensions' => json_encode([]),
            ];

            // 记录发送到景区方系统的数据（包含详细的客人信息）
            Log::info('发送到景区方系统的订单数据', [
                'order_id' => $order->id,
                'ota_order_no' => $order->ota_order_no,
                'guest_info' => $order->guest_info,
                'order_guest_list' => $orderGuestList,
                'order_guest_list_count' => count($orderGuestList),
                'request_data' => $data,
            ]);

            $result = $this->getClient($order)->book($data);

            // 如果失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '未知错误');
                
                $this->createExceptionOrder($order, 'BookRQ', $errorMessage, [
                    'result_code' => $errorCode,
                    'response' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方下单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'BookRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '下单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $otaOrderId, ?Order $order = null): array
    {
        try {
            $result = $this->getClient($order)->query([
                'OtaOrderId' => $otaOrderId,
            ]);

            // 如果查询成功，尝试更新订单状态
            if (($result['success'] ?? false) && $order) {
                $status = (string)($result['data']->Status ?? '');
                $mappedStatus = $this->mapStatus($status);
                
                if ($mappedStatus && $order->status !== $mappedStatus) {
                    $order->update(['status' => $mappedStatus]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方查询订单失败', [
                'ota_order_id' => $otaOrderId,
                'error' => $e->getMessage(),
            ]);

            if ($order) {
                $this->createExceptionOrder($order, 'QueryStatusRQ', $e->getMessage());
            }

            return [
                'success' => false,
                'message' => '查询订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 接单（确认订单）
     * 资源方系统：下单预订（BookRQ）成功后即表示接单
     */
    public function confirmOrder(Order $order): array
    {
        Log::info('HengdianService::confirmOrder 开始', [
            'order_id' => $order->id,
            'resource_order_no' => $order->resource_order_no,
        ]);
        
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                Log::info('HengdianService::confirmOrder: 订单已有资源方订单号，直接返回成功', [
                    'order_id' => $order->id,
                    'resource_order_no' => $order->resource_order_no,
                ]);
                
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 如果没有资源方订单号，调用下单接口（接单）
            Log::info('HengdianService::confirmOrder: 准备调用 book() 方法', [
                'order_id' => $order->id,
            ]);
            
            $result = $this->book($order);
            
            Log::info('HengdianService::confirmOrder: book() 方法返回', [
                'order_id' => $order->id,
                'result_success' => $result['success'] ?? false,
                'result_message' => $result['message'] ?? '',
            ]);

            if ($result['success'] ?? false) {
                // 保存资源方订单号
                $orderId = (string)($result['data']->OrderId ?? '');
                if ($orderId) {
                    $order->update(['resource_order_no' => $orderId]);
                    Log::info('HengdianService::confirmOrder: 已保存资源方订单号', [
                        'order_id' => $order->id,
                        'resource_order_no' => $orderId,
                    ]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'confirmOrder', $e->getMessage());

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 拒单（拒绝订单）
     * 横店系统：通过取消订单接口实现拒单
     */
    public function rejectOrder(Order $order, string $reason): array
    {
        try {
            // 横店系统可能没有专门的拒单接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方拒单', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => '订单已拒绝',
                'data' => ['reason' => $reason],
            ];
        } catch (\Exception $e) {
            Log::error('资源方拒单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '拒单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 核销订单
     * 横店系统：可能需要调用核销接口（需要查看横店文档）
     */
    public function verifyOrder(Order $order, array $data): array
    {
        try {
            // 横店系统可能没有专门的核销接口，这里先返回成功
            // 实际实现需要根据横店系统的具体接口文档
            Log::info('资源方核销订单', [
                'order_id' => $order->id,
                'data' => $data,
            ]);

            return [
                'success' => true,
                'message' => '订单已核销',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('资源方核销失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询订单是否可以取消
     * 
     * 注意：横店系统可能没有专门的查询是否可以取消的接口
     * 这里通过查询订单状态来判断是否可以取消
     * 如果订单状态为已确认且未使用，则可以取消
     * 
     * @param Order $order 订单
     * @return array [
     *     'can_cancel' => bool,  // 是否可以取消
     *     'message' => string,   // 原因说明
     *     'data' => array        // 额外数据
     * ]
     */
    public function canCancelOrder(Order $order): array
    {
        try {
            // 查询订单状态
            $result = $this->queryOrder($order->ota_order_no, $order);

            if (!($result['success'] ?? false)) {
                // 查询失败，返回可以取消（由后续取消接口判断）
                return [
                    'can_cancel' => true,
                    'message' => '查询订单状态失败，允许尝试取消',
                    'data' => ['query_result' => $result],
                ];
            }

            // 根据订单状态判断是否可以取消
            // 这里需要根据横店系统的实际返回数据结构来判断
            // 假设返回的数据中包含订单状态信息
            $orderData = $result['data'] ?? null;
            
            // 如果订单已使用或已取消，则不能取消
            // 具体判断逻辑需要根据横店系统的实际接口返回数据来调整
            if ($orderData) {
                // 这里需要根据实际返回的数据结构来判断
                // 暂时返回可以取消，由取消接口来最终判断
                return [
                    'can_cancel' => true,
                    'message' => '订单可以取消',
                    'data' => ['order_data' => $orderData],
                ];
            }

            // 默认返回可以取消
            return [
                'can_cancel' => true,
                'message' => '订单可以取消',
                'data' => [],
            ];
        } catch (\Exception $e) {
            Log::error('资源方查询是否可以取消失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // 查询异常，返回可以取消（由后续取消接口判断）
            return [
                'can_cancel' => true,
                'message' => '查询是否可以取消失败，允许尝试取消：' . $e->getMessage(),
                'data' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * 取消订单
     */
    public function cancelOrder(Order $order, string $reason): array
    {
        try {
            $result = $this->getClient($order)->cancel([
                'OtaOrderId' => $order->ota_order_no,
                'Reason' => $reason,
            ]);

            // 如果取消接口返回失败，创建异常订单
            if (!($result['success'] ?? false)) {
                $errorCode = (string)($result['data']->ResultCode ?? '');
                $errorMessage = (string)($result['data']->Message ?? $result['message'] ?? '订单不可以取消');
                
                // 如果是 -200 错误码，说明订单已过期不能取消
                if ($errorCode === '-200') {
                    $this->createExceptionOrder($order, 'CancelRQ', '订单已过期，不能取消', [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                } else {
                    $this->createExceptionOrder($order, 'CancelRQ', $errorMessage, [
                        'result_code' => $errorCode,
                        'response' => $result,
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'data' => $result['data'] ?? [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->createExceptionOrder($order, 'CancelRQ', $e->getMessage());

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 订阅库存推送
     * 
     * @param array $hotels 酒店列表，格式：[['hotel_id' => '001', 'room_types' => ['标准间', '豪华间']]]
     * @param string $notifyUrl Webhook URL
     * @param bool $unsubscribe 是否取消订阅
     * @return array
     */
    public function subscribeInventory(array $hotels, string $notifyUrl, bool $unsubscribe = false): array
    {
        try {
            // 构建订阅请求数据
            $hotelsData = [];
            foreach ($hotels as $hotel) {
                $hotelId = $hotel['hotel_id'] ?? '';
                $roomTypes = $hotel['room_types'] ?? [];

                if (empty($hotelId) || empty($roomTypes)) {
                    continue;
                }

                $hotelsData[] = [
                    'HotelId' => $hotelId,
                    'Rooms' => [
                        'RoomType' => $roomTypes, // 索引数组，会生成多个RoomType元素
                    ],
                ];
            }

            $data = [
                'NotifyUrl' => $unsubscribe ? '' : $notifyUrl,
                'IsUnsubscribe' => $unsubscribe ? '1' : '0',
                'Hotels' => $hotelsData,
                'Extensions' => json_encode([]),
            ];

            // 使用默认认证信息（不传订单，所以不传OTA平台代码）
            $config = null;
            if ($this->client) {
                // 如果已有client，使用其配置
                $reflection = new \ReflectionClass($this->client);
                $property = $reflection->getProperty('config');
                $property->setAccessible(true);
                $config = $property->getValue($this->client);
            } else {
                // 否则获取第一个配置
                $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                $config = $provider?->resourceConfigs()->first();
            }

            // 如果还是没有配置，尝试从.env创建临时配置
            if (!$config) {
                $config = $this->createConfigFromEnv();
            }

            if (!$config) {
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
            }

            $client = new HengdianClient($config); // 不传OTA平台代码，使用默认认证
            $result = $client->subscribeRoomStatus($data);

            if (!($result['success'] ?? false)) {
                Log::error('资源方订阅库存推送失败', [
                    'notify_url' => $notifyUrl,
                    'unsubscribe' => $unsubscribe,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('资源方订阅库存推送异常', [
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '订阅失败：' . $e->getMessage(),
            ];
        }
    }
}
