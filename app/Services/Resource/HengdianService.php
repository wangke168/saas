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
    protected ?ResourceConfig $config = null;

    /**
     * 设置资源配置（由 ResourceServiceFactory 调用）
     */
    public function setConfig(ResourceConfig $config): self
    {
        // 确保 softwareProvider 关系已加载（解决队列序列化后关系丢失的问题）
        if (!$config->relationLoaded('softwareProvider') && $config->software_provider_id) {
            $config->load('softwareProvider');
        }
        
        $this->config = $config;
        // 重置客户端，以便使用新配置
        $this->client = null;
        return $this;
    }

    /**
     * 根据订单获取对应的客户端
     * 优先使用 setConfig() 设置的配置，如果没有则使用旧的逻辑（向后兼容）
     */
    protected function getClient(?Order $order = null): HengdianClient
    {
        if ($this->client === null) {
            Log::info('HengdianService::getClient 开始获取配置', [
                'order_id' => $order?->id,
                'has_order' => $order !== null,
                'has_config' => $this->config !== null,
                'config_id' => $this->config?->id,
            ]);
            
            $config = $this->config;
            
            // 如果已经通过 setConfig() 设置了配置，直接使用
            if ($config) {
                Log::info('HengdianService::getClient: 使用 ResourceServiceFactory 传递的配置', [
                    'order_id' => $order?->id,
                    'config_id' => $config->id,
                    'scenic_spot_id' => $config->scenic_spot_id,
                    'software_provider_id' => $config->software_provider_id,
                    'config_api_url' => $config->api_url,
                ]);
            } else {
                // 向后兼容：如果没有设置配置，使用旧的逻辑
                Log::info('HengdianService::getClient: 使用向后兼容逻辑获取配置', [
                    'order_id' => $order?->id,
                ]);
                
                // 如果提供了订单，根据订单的景区获取配置
                if ($order) {
                    $scenicSpot = $order->hotel->scenicSpot ?? null;
                    if ($scenicSpot) {
                        // 尝试从产品的服务商获取配置
                        $order->loadMissing('product.softwareProvider');
                        $product = $order->product;
                        if ($product && $product->softwareProvider) {
                            $config = ResourceConfig::with('softwareProvider')
                                ->where('scenic_spot_id', $scenicSpot->id)
                                ->where('software_provider_id', $product->softwareProvider->id)
                                ->first();
                            
                            if ($config) {
                                Log::info('HengdianService::getClient: 从产品服务商配置获取成功', [
                                    'order_id' => $order->id,
                                    'config_id' => $config->id,
                                    'config_api_url' => $config->api_url,
                                ]);
                            }
                        }
                    }
                }
                
                // 如果还是没有配置，尝试使用第一个配置（向后兼容）
                if (!$config) {
                    $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                    $config = $provider?->resourceConfigs()->with('softwareProvider')->first();
                }
                
                // 如果还是没有配置，尝试从.env创建临时配置
                if (!$config) {
                    $config = $this->createConfigFromEnv();
                }
                
                if (!$config) {
                    Log::error('HengdianService::getClient: 配置不存在', [
                        'order_id' => $order?->id,
                        'error' => '资源方配置不存在，请检查数据库配置或环境变量',
                    ]);
                    throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量');
                }
            }
            
            // 确保 config 的 softwareProvider 关系已加载（防止队列序列化后丢失）
            if ($config && !$config->relationLoaded('softwareProvider') && $config->software_provider_id) {
                $config->load('softwareProvider');
            }
            
            // 验证 api_url 是否存在（在访问 api_url 之前先确保关系已加载）
            if ($config) {
                // 再次确保关系已加载（访问器可能已经加载，但这里确保一下）
                if (!$config->relationLoaded('softwareProvider') && $config->software_provider_id) {
                    $config->load('softwareProvider');
                }
                
                // 获取 api_url（访问器会自动处理）
                $apiUrl = $config->api_url;
                
                // 详细日志用于调试
                Log::info('HengdianService::getClient: 验证 api_url', [
                    'order_id' => $order?->id,
                    'config_id' => $config->id ?? 'from_env',
                    'software_provider_id' => $config->software_provider_id ?? null,
                    'has_software_provider' => $config->softwareProvider !== null,
                    'software_provider_id_from_relation' => $config->softwareProvider?->id,
                    'software_provider_name' => $config->softwareProvider?->name,
                    'software_provider_api_url' => $config->softwareProvider?->api_url,
                    'config_api_url' => $apiUrl,
                    'api_url_empty' => empty($apiUrl),
                ]);
                
                if (empty($apiUrl)) {
                    Log::error('HengdianService::getClient: 服务商API地址未配置', [
                        'order_id' => $order?->id,
                        'config_id' => $config->id ?? 'from_env',
                        'software_provider_id' => $config->software_provider_id ?? null,
                        'has_software_provider' => $config->softwareProvider !== null,
                        'software_provider_api_url' => $config->softwareProvider?->api_url,
                    ]);
                    throw new \Exception('服务商API地址未配置，无法处理订单。请在软件服务商管理页面配置API地址。');
                }
            }
            
            // 获取订单的OTA平台代码
            $otaPlatformCode = $order?->otaPlatform?->code?->value ?? null;
            
            Log::info('HengdianService::getClient: 准备创建 HengdianClient', [
                'order_id' => $order?->id,
                'config_id' => $config->id ?? 'from_env',
                'config_api_url' => $config->api_url ?? null,
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
        // 临时配置：将api_url存储在extra_config中（因为临时配置没有softwareProvider）
        $config->username = env('HENGDIAN_USERNAME');
        $config->password = env('HENGDIAN_PASSWORD');
        $config->environment = 'production';
        $config->is_active = true;
        $config->extra_config = [
            'api_url_override' => env('HENGDIAN_API_URL'), // 临时配置的API地址
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
     * 查询订单状态（包括核销状态）
     * 
     * @param Order|string $orderOrOrderNo 订单对象或订单号
     * @return array
     */
    public function queryOrderStatus($orderOrOrderNo): array
    {
        try {
            // 获取订单号
            $otaOrderNo = $orderOrOrderNo instanceof Order ? $orderOrOrderNo->ota_order_no : $orderOrOrderNo;
            $order = $orderOrOrderNo instanceof Order ? $orderOrOrderNo : null;

            // 调用查询订单接口
            $result = $this->queryOrder($otaOrderNo, $order);

            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '查询订单状态失败',
                    'data' => [],
                ];
            }

            // 解析返回数据
            $responseData = $result['data'] ?? null;
            if (!$responseData) {
                return [
                    'success' => false,
                    'message' => '查询结果数据为空',
                    'data' => [],
                ];
            }

            // 提取订单状态（横店系统状态：1=预订成功，4=已取消，5=已使用/已核销）
            $status = (string)($responseData->Status ?? '');
            $mappedStatus = $this->mapStatus($status);

            // 构建返回数据
            $data = [
                'order_no' => $otaOrderNo,
                'status' => $mappedStatus?->value ?? 'unknown',
                'verified_at' => null,
                'use_start_date' => null,
                'use_end_date' => null,
                'use_quantity' => null,
                'passengers' => [],
                'vouchers' => [],
            ];

            // 如果订单已核销（Status='5'），提取核销相关信息
            if ($status === '5' && $order) {
                // 使用订单信息填充核销数据
                $data['verified_at'] = $order->updated_at?->toIso8601String();
                $data['use_start_date'] = $order->check_in_date?->format('Y-m-d');
                $data['use_end_date'] = $order->check_out_date?->format('Y-m-d');
                $data['use_quantity'] = $order->room_count ?? null;

                // 如果有客人信息，转换为passengers格式
                if (!empty($order->guest_info) && is_array($order->guest_info)) {
                    $data['passengers'] = array_map(function($guest) {
                        return [
                            'name' => $guest['name'] ?? $guest['Name'] ?? '',
                            'idCode' => $guest['idCode'] ?? $guest['IdCode'] ?? $guest['credentialNo'] ?? '',
                            'credentialType' => $guest['credentialType'] ?? 0,
                        ];
                    }, $order->guest_info);
                }
            }

            // 如果有其他核销相关字段（根据实际横店接口返回调整）
            // 例如：核销时间、使用日期等可能从接口返回的数据中获取
            if (isset($responseData->VerifiedTime)) {
                try {
                    $data['verified_at'] = (string)$responseData->VerifiedTime;
                } catch (\Exception $e) {
                    Log::warning('解析核销时间失败', [
                        'ota_order_no' => $otaOrderNo,
                        'verified_time' => $responseData->VerifiedTime,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (isset($responseData->UseStartDate)) {
                $data['use_start_date'] = (string)$responseData->UseStartDate;
            }

            if (isset($responseData->UseEndDate)) {
                $data['use_end_date'] = (string)$responseData->UseEndDate;
            }

            if (isset($responseData->UseQuantity)) {
                $data['use_quantity'] = (int)$responseData->UseQuantity;
            }

            Log::info('查询订单状态成功', [
                'ota_order_no' => $otaOrderNo,
                'status' => $status,
                'mapped_status' => $mappedStatus?->value,
                'is_verified' => $status === '5',
            ]);

            return [
                'success' => true,
                'message' => '查询成功',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('查询订单状态异常', [
                'order_or_order_no' => $orderOrOrderNo instanceof Order ? $orderOrOrderNo->id : $orderOrOrderNo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '查询订单状态异常：' . $e->getMessage(),
                'data' => [],
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
     * 订阅库存推送（房态订阅）
     * 
     * 根据横店系统接口文档（storage/docs/hengdian/hengdian.txt），房态订阅接口说明：
     * - 接口节点：<SubscribeRoomStatusRQ>
     * - 功能：订阅、取消订阅、修改推送地址
     * - 横店系统会定期（每5-10分钟）推送房态信息到指定的NotifyUrl
     * 
     * 重要说明：
     * 1. HotelId 应使用 hotel.txt 中的酒店ID（短编号，如 001, 002, 2078）
     * 2. RoomType 应使用横店系统的房型名称（如"标准间"、"大床房"）
     * 3. hotel_id 应直接使用数据库中的 hotel.external_code 值
     * 
     * 参数说明：
     * @param array $hotels 酒店列表，格式：
     *   [
     *     [
     *       'hotel_id' => '001',  // 横店系统的酒店ID（来自 hotel.txt，如 001, 002, 2078）
     *                              // 应存储在 hotel.external_code 字段中
     *       'room_types' => ['标准间', '大床房']  // 横店系统的房型名称列表
     *     ]
     *   ]
     *   参考文档：
     *   - storage/docs/hengdian/hengdian.txt（接口文档）
     *   - storage/docs/hengdian/hotel.txt（酒店ID映射表，用于订阅）
     * 
     * @param string $notifyUrl Webhook接收地址，格式：http://your-domain.com/api/webhooks/resource/hengdian/inventory
     * @param bool $unsubscribe 是否取消订阅（true=取消订阅，false=订阅）
     * @return array 返回结果，格式：['success' => true/false, 'message' => '...', 'data' => ...]
     */
    public function subscribeInventory(array $hotels, string $notifyUrl, bool $unsubscribe = false): array
    {
        try {
            Log::info('横店房态订阅开始', [
                'hotels_count' => count($hotels),
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
            ]);

            // 构建订阅请求数据
            $hotelsData = [];
            $skippedCount = 0;
            
            foreach ($hotels as $index => $hotel) {
                $hotelId = $hotel['hotel_id'] ?? '';
                $roomTypes = $hotel['room_types'] ?? [];

                // 跳过无效的酒店数据
                if (empty($hotelId) || empty($roomTypes)) {
                    $skippedCount++;
                    Log::warning('横店房态订阅：跳过无效酒店数据', [
                        'index' => $index,
                        'hotel_id' => $hotelId,
                        'room_types_count' => count($roomTypes),
                    ]);
                    continue;
                }

                // 过滤空的房型名称
                $validRoomTypes = array_filter($roomTypes, function($roomType) {
                    return !empty(trim($roomType));
                });

                if (empty($validRoomTypes)) {
                    $skippedCount++;
                    Log::warning('横店房态订阅：酒店无有效房型', [
                        'hotel_id' => $hotelId,
                    ]);
                    continue;
                }

                $hotelsData[] = [
                    'HotelId' => (string)$hotelId,  // 确保是字符串
                    'Rooms' => [
                        'RoomType' => array_values($validRoomTypes), // 索引数组，会生成多个RoomType元素
                    ],
                ];
            }

            if (empty($hotelsData)) {
                $message = '没有有效的酒店和房型数据，已跳过 ' . $skippedCount . ' 条无效数据';
                Log::warning('横店房态订阅：没有有效数据', [
                    'total_hotels' => count($hotels),
                    'skipped_count' => $skippedCount,
                ]);
                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            // 构建符合文档规范的XML结构
            // 文档要求：<Hotels><Hotel><HotelId>...</HotelId><Rooms>...</Rooms></Hotel></Hotels>
            $data = [
                'NotifyUrl' => $unsubscribe ? '' : $notifyUrl,
                'IsUnsubscribe' => $unsubscribe ? '1' : '0',
                'Hotels' => [
                    'Hotel' => $hotelsData,  // 使用 'Hotel' 作为键，这样会生成 <Hotels><Hotel>...</Hotel></Hotels> 结构
                ],
                'Extensions' => json_encode([]),
            ];

            Log::info('横店房态订阅：构建请求数据', [
                'hotels_count' => count($hotelsData),
                'total_room_types' => array_sum(array_map(function($h) {
                    return count($h['Rooms']['RoomType']);
                }, $hotelsData)),
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
            ]);

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
                $config = $provider?->resourceConfigs()->with('softwareProvider')->first();
            }

            // 如果还是没有配置，尝试从.env创建临时配置
            if (!$config) {
                $config = $this->createConfigFromEnv();
            }

            if (!$config) {
                throw new \Exception('资源方配置不存在，请检查数据库配置或环境变量（HENGDIAN_API_URL, HENGDIAN_USERNAME, HENGDIAN_PASSWORD）');
            }

            $client = new HengdianClient($config); // 不传OTA平台代码，使用默认认证
            $result = $client->subscribeRoomStatus($data);

            if ($result['success'] ?? false) {
                Log::info('横店房态订阅成功', [
                    'notify_url' => $notifyUrl,
                    'unsubscribe' => $unsubscribe,
                    'hotels_count' => count($hotelsData),
                    'message' => $result['message'] ?? '',
                ]);
            } else {
                Log::error('横店房态订阅失败', [
                    'notify_url' => $notifyUrl,
                    'unsubscribe' => $unsubscribe,
                    'result' => $result,
                    'hotels_count' => count($hotelsData),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('横店房态订阅异常', [
                'notify_url' => $notifyUrl,
                'unsubscribe' => $unsubscribe,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '订阅失败：' . $e->getMessage(),
            ];
        }
    }
}
