<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\RoomType;
use App\Models\Product;
use App\Jobs\PushChangedInventoryToOtaJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InventoryPushController extends Controller
{
    /**
     * 推送单个库存到OTA
     */
    public function pushInventory(Inventory $inventory): JsonResponse
    {
        try {
            $roomType = $inventory->roomType;
            if (!$roomType) {
                return response()->json([
                    'success' => false,
                    'message' => '房型不存在',
                ], 404);
            }

            $hotel = $roomType->hotel;
            if (!$hotel) {
                return response()->json([
                    'success' => false,
                    'message' => '酒店不存在',
                ], 404);
            }

            // 检查是否有产品关联
            $products = Product::whereHas('prices', function ($q) use ($roomType) {
                $q->where('room_type_id', $roomType->id);
            })
            ->where('is_active', true)
            ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '该房型没有关联的产品',
                ], 400);
            }

            // 检查产品是否已推送到OTA
            $hasOtaProduct = false;
            foreach ($products as $product) {
                if ($product->otaProducts()->where('is_active', true)->exists()) {
                    $hasOtaProduct = true;
                    break;
                }
            }

            if (!$hasOtaProduct) {
                return response()->json([
                    'success' => false,
                    'message' => '该房型关联的产品未推送到OTA平台',
                ], 400);
            }

            // 推送库存
            PushChangedInventoryToOtaJob::dispatch(
                $roomType->id,
                [$inventory->date->format('Y-m-d')],
                null // 推送到所有已推送的平台
            )->onQueue('ota-push');

            Log::info('手动推送库存到OTA', [
                'inventory_id' => $inventory->id,
                'room_type_id' => $roomType->id,
                'date' => $inventory->date->format('Y-m-d'),
            ]);

            return response()->json([
                'success' => true,
                'message' => '推送任务已提交，正在后台处理中',
            ]);
        } catch (\Exception $e) {
            Log::error('手动推送库存到OTA失败', [
                'inventory_id' => $inventory->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 批量推送库存到OTA
     */
    public function batchPushInventory(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'room_type_id' => 'required|integer|exists:room_types,id',
                'dates' => 'required|array',
                'dates.*' => 'required|date',
                'ota_platform_id' => 'nullable|integer|exists:ota_platforms,id',
            ]);

            $roomType = RoomType::with('hotel')->find($validated['room_type_id']);
            if (!$roomType) {
                return response()->json([
                    'success' => false,
                    'message' => '房型不存在',
                ], 404);
            }

            // 检查是否有产品关联
            $products = Product::whereHas('prices', function ($q) use ($roomType) {
                $q->where('room_type_id', $roomType->id);
            })
            ->where('is_active', true)
            ->get();

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '该房型没有关联的产品',
                ], 400);
            }

            // 检查产品是否已推送到OTA
            $hasOtaProduct = false;
            foreach ($products as $product) {
                if ($product->otaProducts()->where('is_active', true)->exists()) {
                    $hasOtaProduct = true;
                    break;
                }
            }

            if (!$hasOtaProduct) {
                return response()->json([
                    'success' => false,
                    'message' => '该房型关联的产品未推送到OTA平台',
                ], 400);
            }

            // 推送库存
            PushChangedInventoryToOtaJob::dispatch(
                $roomType->id,
                $validated['dates'],
                $validated['ota_platform_id'] ?? null
            )->onQueue('ota-push');

            Log::info('批量推送库存到OTA', [
                'room_type_id' => $roomType->id,
                'dates_count' => count($validated['dates']),
                'dates' => array_slice($validated['dates'], 0, 10), // 只记录前10个日期
                'ota_platform_id' => $validated['ota_platform_id'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => '批量推送任务已提交，正在后台处理中',
                'data' => [
                    'room_type_id' => $roomType->id,
                    'dates_count' => count($validated['dates']),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('批量推送库存到OTA失败', [
                'room_type_id' => $validated['room_type_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '推送失败：' . $e->getMessage(),
            ], 500);
        }
    }
}




