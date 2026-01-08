<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Enums\PriceSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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
            $inventory = Inventory::updateOrCreate(
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
            $inventories[] = $inventory;

            // 清除 Redis 指纹，确保下次资源方推送时能正常更新
            try {
                $date = is_string($invData['date']) ? $invData['date'] : $inventory->date->format('Y-m-d');
                $fingerprintKey = "inventory:fingerprint:{$validated['room_type_id']}:{$date}";
                Redis::del($fingerprintKey);
                
                Log::info('批量创建库存：已清除 Redis 指纹', [
                    'room_type_id' => $validated['room_type_id'],
                    'date' => $date,
                    'fingerprint_key' => $fingerprintKey,
                ]);
            } catch (\Exception $e) {
                // Redis 操作失败不影响库存创建，只记录日志
                $dateForLog = is_string($invData['date']) ? $invData['date'] : ($inventory->date->format('Y-m-d') ?? 'unknown');
                Log::warning('批量创建库存：清除 Redis 指纹失败', [
                    'room_type_id' => $validated['room_type_id'],
                    'date' => $dateForLog,
                    'error' => $e->getMessage(),
                ]);
            }
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
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
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

        // 清除 Redis 指纹，确保下次资源方推送时能正常更新
        // 当手工编辑库存后，需要清除对应的 Redis 指纹，否则资源方推送时
        // 会认为库存值没有变化（因为指纹比对相同）而跳过更新
        try {
            $fingerprintKey = "inventory:fingerprint:{$inventory->room_type_id}:{$inventory->date->format('Y-m-d')}";
            Redis::del($fingerprintKey);
            
            Log::info('手工编辑库存：已清除 Redis 指纹', [
                'inventory_id' => $inventory->id,
                'room_type_id' => $inventory->room_type_id,
                'date' => $inventory->date->format('Y-m-d'),
                'fingerprint_key' => $fingerprintKey,
            ]);
        } catch (\Exception $e) {
            // Redis 操作失败不影响库存更新，只记录日志
            Log::warning('手工编辑库存：清除 Redis 指纹失败', [
                'inventory_id' => $inventory->id,
                'error' => $e->getMessage(),
            ]);
        }

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

        // 清除 Redis 指纹，确保下次资源方推送时能正常更新
        try {
            $fingerprintKey = "inventory:fingerprint:{$inventory->room_type_id}:{$inventory->date->format('Y-m-d')}";
            Redis::del($fingerprintKey);
        } catch (\Exception $e) {
            Log::warning('关闭库存：清除 Redis 指纹失败', [
                'inventory_id' => $inventory->id,
                'error' => $e->getMessage(),
            ]);
        }

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

        // 清除 Redis 指纹，确保下次资源方推送时能正常更新
        try {
            $fingerprintKey = "inventory:fingerprint:{$inventory->room_type_id}:{$inventory->date->format('Y-m-d')}";
            Redis::del($fingerprintKey);
        } catch (\Exception $e) {
            Log::warning('开启库存：清除 Redis 指纹失败', [
                'inventory_id' => $inventory->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => '库存已开启',
        ]);
    }
}
