<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Enums\PriceSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    /**
     * 库存列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Inventory::with(['roomType.hotel']);

        if ($request->has('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        // 权限控制：运营只能查看所属资源方下的所有景区下的酒店的房型的库存
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereHas('roomType.hotel', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }

        $inventories = $query->orderBy('date')->paginate($request->get('per_page', 50));

        return response()->json($inventories);
    }

    /**
     * 批量创建库存
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'inventories' => 'required|array',
            'inventories.*.date' => 'required|date',
            'inventories.*.total_quantity' => 'required|integer|min:0',
            'inventories.*.available_quantity' => 'required|integer|min:0',
        ]);

        // 权限控制：运营只能在自己所属资源方下的景区下的酒店的房型中创建库存
        if ($request->user()->isOperator()) {
            $roomType = \App\Models\RoomType::with('hotel')->findOrFail($validated['room_type_id']);
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($roomType->hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权在该房型下创建库存',
                ], 403);
            }
        }

        $inventories = [];
        foreach ($validated['inventories'] as $invData) {
            $inventories[] = Inventory::updateOrCreate(
                [
                    'room_type_id' => $validated['room_type_id'],
                    'date' => $invData['date'],
                ],
                array_merge($invData, [
                    'locked_quantity' => 0,
                    'source' => PriceSource::MANUAL,
                    'is_closed' => false,
                ])
            );
        }

        return response()->json([
            'message' => '库存创建成功',
            'data' => $inventories,
        ], 201);
    }

    /**
     * 更新库存
     */
    public function update(Request $request, Inventory $inventory): JsonResponse
    {
        // 权限控制：运营只能更新自己绑定的景区下的酒店的房型的库存
        if ($request->user()->isOperator()) {
            $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
            $inventory->load('roomType.hotel');
            if (! $scenicSpotIds->contains($inventory->roomType->hotel->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该库存',
                ], 403);
            }
        }

        // 接口推送的库存不允许人工修改
        if ($inventory->source === PriceSource::API) {
            return response()->json([
                'message' => '接口推送的库存不允许人工修改',
            ], 403);
        }

        $validated = $request->validate([
            'total_quantity' => 'sometimes|required|integer|min:0',
            'available_quantity' => 'sometimes|required|integer|min:0',
            'is_closed' => 'sometimes|boolean',
        ]);

        // 确保可用库存不超过总库存
        if (isset($validated['available_quantity']) && isset($validated['total_quantity'])) {
            if ($validated['available_quantity'] > $validated['total_quantity']) {
                return response()->json([
                    'message' => '可用库存不能超过总库存',
                ], 422);
            }
        } elseif (isset($validated['available_quantity'])) {
            if ($validated['available_quantity'] > $inventory->total_quantity) {
                return response()->json([
                    'message' => '可用库存不能超过总库存',
                ], 422);
            }
        } elseif (isset($validated['total_quantity'])) {
            if ($inventory->available_quantity > $validated['total_quantity']) {
                return response()->json([
                    'message' => '总库存不能小于当前可用库存',
                ], 422);
            }
        }

        $inventory->update($validated);
        $inventory->load(['roomType.hotel']);

        return response()->json([
            'message' => '库存更新成功',
            'data' => $inventory,
        ]);
    }

    /**
     * 关闭库存（人工关闭）
     */
    public function close(Inventory $inventory): JsonResponse
    {
        $inventory->update(['is_closed' => true]);

        return response()->json([
            'message' => '库存已关闭',
        ]);
    }

    /**
     * 开启库存
     */
    public function open(Inventory $inventory): JsonResponse
    {
        $inventory->update(['is_closed' => false]);

        return response()->json([
            'message' => '库存已开启',
        ]);
    }
}
