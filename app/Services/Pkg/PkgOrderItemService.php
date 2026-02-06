<?php

namespace App\Services\Pkg;

use App\Models\Pkg\PkgOrder;
use App\Models\Pkg\PkgOrderItem;
use App\Enums\PkgOrderItemStatus;
use App\Enums\PkgOrderItemType;
use App\Services\Resource\ResourceServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PkgOrderItemService
{
    /**
     * 订单项接单
     */
    public function confirmItem(PkgOrderItem $item, ?int $operatorId = null): array
    {
        try {
            DB::beginTransaction();

            // 验证状态
            if ($item->status !== PkgOrderItemStatus::PENDING) {
                return [
                    'success' => false,
                    'message' => '订单项状态不允许接单，当前状态：' . $item->status->label(),
                ];
            }

            // 更新状态为处理中
            $item->update([
                'status' => PkgOrderItemStatus::PROCESSING,
                'processed_at' => now(),
            ]);

            // 根据订单项类型处理
            if ($item->item_type === PkgOrderItemType::TICKET) {
                $result = $this->confirmTicketItem($item);
            } elseif ($item->item_type === PkgOrderItemType::HOTEL) {
                $result = $this->confirmHotelItem($item);
            } else {
                $result = [
                    'success' => false,
                    'message' => '未知的订单项类型',
                ];
            }

            if ($result['success']) {
                // 接单成功，更新订单项状态
                $item->update([
                    'status' => PkgOrderItemStatus::SUCCESS,
                    'resource_order_no' => $result['resource_order_no'] ?? null,
                ]);

                // 检查并更新主订单状态
                $this->updateMainOrderStatus($item->order);

                DB::commit();

                return [
                    'success' => true,
                    'message' => '接单成功',
                    'data' => [
                        'item_id' => $item->id,
                        'status' => $item->status->value,
                        'resource_order_no' => $item->resource_order_no,
                    ],
                ];
            } else {
                // 接单失败
                $item->update([
                    'status' => PkgOrderItemStatus::FAILED,
                    'error_message' => $result['message'] ?? '接单失败',
                ]);

                // 检查并更新主订单状态
                $this->updateMainOrderStatus($item->order);

                DB::commit();

                return [
                    'success' => false,
                    'message' => $result['message'] ?? '接单失败',
                ];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PkgOrderItemService: 订单项接单失败', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 门票订单项接单
     */
    protected function confirmTicketItem(PkgOrderItem $item): array
    {
        try {
            $ticket = \App\Models\Ticket::with(['scenicSpot.resourceConfig', 'scenicSpot.softwareProvider'])
                ->find($item->resource_id);

            if (!$ticket) {
                return ['success' => false, 'message' => '门票不存在'];
            }

            // 检查是否系统直连
            $isSystemConnected = $this->isSystemConnectedForTicket($ticket);

            if (!$isSystemConnected) {
                // 非系统直连：人工接单，直接标记为成功
                return [
                    'success' => true,
                    'message' => '人工接单成功',
                    'resource_order_no' => 'MANUAL_' . $item->id,
                ];
            }

            // 系统直连：调用资源方接口
            // TODO: 实现门票接单接口调用
            // 目前先返回成功，后续需要实现实际接口调用
            Log::info('PkgOrderItemService: 门票订单项系统直连，待实现接口调用', [
                'item_id' => $item->id,
                'ticket_id' => $ticket->id,
            ]);

            return [
                'success' => true,
                'message' => '接单成功（系统直连，待实现接口）',
                'resource_order_no' => 'AUTO_' . $item->id,
            ];
        } catch (\Exception $e) {
            Log::error('PkgOrderItemService: 门票订单项接单失败', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 酒店订单项接单
     */
    protected function confirmHotelItem(PkgOrderItem $item): array
    {
        try {
            $roomType = \App\Models\Res\ResRoomType::with(['hotel.scenicSpot.resourceConfig', 'hotel.scenicSpot.softwareProvider'])
                ->find($item->resource_id);

            if (!$roomType) {
                return ['success' => false, 'message' => '房型不存在'];
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                return ['success' => false, 'message' => '酒店不存在'];
            }

            // 检查是否系统直连
            $isSystemConnected = $this->isSystemConnectedForHotel($hotel);

            if (!$isSystemConnected) {
                // 非系统直连：人工接单，直接标记为成功
                return [
                    'success' => true,
                    'message' => '人工接单成功',
                    'resource_order_no' => 'MANUAL_' . $item->id,
                ];
            }

            // 系统直连：调用资源方接口
            // TODO: 实现酒店接单接口调用
            // 目前先返回成功，后续需要实现实际接口调用
            Log::info('PkgOrderItemService: 酒店订单项系统直连，待实现接口调用', [
                'item_id' => $item->id,
                'room_type_id' => $roomType->id,
            ]);

            return [
                'success' => true,
                'message' => '接单成功（系统直连，待实现接口）',
                'resource_order_no' => 'AUTO_' . $item->id,
            ];
        } catch (\Exception $e) {
            Log::error('PkgOrderItemService: 酒店订单项接单失败', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '接单失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 订单项核销
     */
    public function verifyItem(PkgOrderItem $item, array $data, ?int $operatorId = null): array
    {
        try {
            DB::beginTransaction();

            // 验证状态
            if ($item->status !== PkgOrderItemStatus::SUCCESS) {
                return [
                    'success' => false,
                    'message' => '订单项状态不允许核销，当前状态：' . $item->status->label(),
                ];
            }

            // TODO: 实现核销逻辑
            // 1. 调用资源方接口核销（如果系统直连）
            // 2. 或者标记为已核销（如果非系统直连）
            // 3. 更新订单项状态（可能需要新增VERIFIED状态）

            Log::info('PkgOrderItemService: 订单项核销', [
                'item_id' => $item->id,
                'data' => $data,
            ]);

            // 暂时标记为成功（实际应该等接口调用成功后再更新）
            // 注意：如果需要新增VERIFIED状态，需要先更新枚举类

            DB::commit();

            return [
                'success' => true,
                'message' => '核销成功',
                'data' => [
                    'item_id' => $item->id,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PkgOrderItemService: 订单项核销失败', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => '核销失败：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 检查门票是否系统直连
     */
    protected function isSystemConnectedForTicket(\App\Models\Ticket $ticket): bool
    {
        $scenicSpot = $ticket->scenicSpot;
        if (!$scenicSpot) {
            return false;
        }

        $softwareProvider = $ticket->softwareProvider ?? $scenicSpot->softwareProvider;
        if (!$softwareProvider) {
            return false;
        }

        $config = \App\Models\ResourceConfig::where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();

        if (!$config) {
            return false;
        }

        $syncMode = $config->extra_config['sync_mode'] ?? [];
        $orderMode = $syncMode['order'] ?? 'manual';

        return $orderMode === 'auto';
    }

    /**
     * 检查酒店是否系统直连
     */
    protected function isSystemConnectedForHotel(\App\Models\Res\ResHotel $hotel): bool
    {
        $scenicSpot = $hotel->scenicSpot;
        if (!$scenicSpot) {
            return false;
        }

        $softwareProvider = $hotel->softwareProvider ?? $scenicSpot->softwareProvider;
        if (!$softwareProvider) {
            return false;
        }

        $config = \App\Models\ResourceConfig::where('scenic_spot_id', $scenicSpot->id)
            ->where('software_provider_id', $softwareProvider->id)
            ->first();

        if (!$config) {
            return false;
        }

        $syncMode = $config->extra_config['sync_mode'] ?? [];
        $orderMode = $syncMode['order'] ?? 'manual';

        return $orderMode === 'auto';
    }

    /**
     * 更新主订单状态
     */
    protected function updateMainOrderStatus(PkgOrder $order): void
    {
        $order->refresh();
        $order->load('items');

        $items = $order->items;
        if ($items->isEmpty()) {
            return;
        }

        // 检查所有订单项的状态
        $allSuccess = $items->every(function ($item) {
            return $item->status === PkgOrderItemStatus::SUCCESS;
        });

        $hasFailed = $items->contains(function ($item) {
            return $item->status === PkgOrderItemStatus::FAILED;
        });

        $hasPending = $items->contains(function ($item) {
            return in_array($item->status, [
                PkgOrderItemStatus::PENDING,
                PkgOrderItemStatus::PROCESSING,
            ]);
        });

        // 更新主订单状态
        if ($allSuccess) {
            // 所有订单项都成功，主订单状态为已确认
            if ($order->status !== \App\Enums\PkgOrderStatus::CONFIRMED) {
                $order->update([
                    'status' => \App\Enums\PkgOrderStatus::CONFIRMED,
                    'confirmed_at' => now(),
                ]);

                Log::info('PkgOrderItemService: 主订单状态更新为已确认', [
                    'pkg_order_id' => $order->id,
                    'order_no' => $order->order_no,
                ]);
            }
        } elseif ($hasFailed && !$hasPending) {
            // 有失败且没有待处理的，主订单状态为失败
            if ($order->status !== \App\Enums\PkgOrderStatus::FAILED) {
                $order->update([
                    'status' => \App\Enums\PkgOrderStatus::FAILED,
                ]);

                Log::warning('PkgOrderItemService: 主订单状态更新为失败', [
                    'pkg_order_id' => $order->id,
                    'order_no' => $order->order_no,
                ]);
            }
        }
        // 如果有待处理的订单项，主订单状态保持当前状态
    }
}


