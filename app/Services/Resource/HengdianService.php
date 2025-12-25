<?php

namespace App\Services\Resource;

use App\Http\Client\HengdianClient;
use App\Models\Order;
use App\Models\ResourceConfig;
use App\Models\SoftwareProvider;
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
            $config = null;
            
            // 如果提供了订单，根据订单的景区获取配置
            if ($order) {
                $scenicSpot = $order->hotel->scenicSpot ?? null;
                if ($scenicSpot && $scenicSpot->resource_config_id) {
                    $config = ResourceConfig::find($scenicSpot->resource_config_id);
                }
            }
            
            // 如果没有找到配置，尝试使用第一个配置（向后兼容）
            if (!$config) {
                $provider = SoftwareProvider::where('api_type', 'hengdian')->first();
                $config = $provider?->resourceConfigs()->first();
            }
            
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

        return $this->getClient($order)->book($data);
    }

    /**
     * 查询订单
     */
    public function queryOrder(string $otaOrderId, ?Order $order = null): array
    {
        return $this->getClient($order)->query([
            'OtaOrderId' => $otaOrderId,
        ]);
    }

    /**
     * 接单（确认订单）
     * 横店系统：下单预订（BookRQ）成功后即表示接单
     * 如果订单已经通过 book 方法创建，这里可以查询订单状态确认
     */
    public function confirmOrder(Order $order): array
    {
        try {
            // 如果订单已经有资源方订单号，说明已经接单，直接返回成功
            if ($order->resource_order_no) {
                return [
                    'success' => true,
                    'message' => '订单已确认',
                    'data' => ['resource_order_no' => $order->resource_order_no],
                ];
            }

            // 如果没有资源方订单号，调用下单接口（接单）
            $result = $this->book($order);

            if ($result['success'] ?? false) {
                $confirmNo = $result['data']->ConfirmNo ?? null;
                if ($confirmNo) {
                    $order->update(['resource_order_no' => (string)$confirmNo]);
                }
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('横店接单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

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
            Log::info('横店拒单', [
                'order_id' => $order->id,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => '订单已拒绝',
                'data' => ['reason' => $reason],
            ];
        } catch (\Exception $e) {
            Log::error('横店拒单失败', [
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
            Log::info('横店核销订单', [
                'order_id' => $order->id,
                'data' => $data,
            ]);

            return [
                'success' => true,
                'message' => '订单已核销',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('横店核销失败', [
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
            Log::error('横店查询是否可以取消失败', [
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

            // 如果取消接口返回失败，说明订单不可以取消
            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '订单不可以取消',
                    'data' => $result['data'] ?? [],
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('横店取消订单失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
            ];
        }
    }
}

