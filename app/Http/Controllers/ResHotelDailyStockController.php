<?php

namespace App\Http\Controllers;

use App\Models\Res\ResHotelDailyStock;
use App\Models\Res\ResRoomType;
use App\Enums\PriceSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ResHotelDailyStockController extends Controller
{
    /**
     * 价库列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = ResHotelDailyStock::with(['hotel.scenicSpot', 'roomType']);

        // 筛选条件
        if ($request->has('hotel_id')) {
            $query->where('hotel_id', $request->hotel_id);
        }

        if ($request->has('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        if ($request->has('start_date')) {
            $query->where('biz_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('biz_date', '<=', $request->end_date);
        }

        if ($request->has('source')) {
            $query->where('source', $request->source);
        }

        // 排序
        $query->orderBy('biz_date', 'desc')->orderBy('created_at', 'desc');

        $stocks = $query->paginate($request->get('per_page', 15));

        return response()->json($stocks);
    }

    /**
     * 创建单个价库记录
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => 'required|exists:res_hotels,id',
            'room_type_id' => 'required|exists:res_room_types,id',
            'biz_date' => 'required|date',
            'cost_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0',
            'stock_total' => 'nullable|integer|min:0',
            'stock_sold' => 'nullable|integer|min:0',
        ]);

        // 验证房型是否属于指定酒店
        $roomType = ResRoomType::findOrFail($validated['room_type_id']);
        if ($roomType->hotel_id != $validated['hotel_id']) {
            return response()->json([
                'message' => '房型不属于指定的酒店',
            ], 422);
        }

        // 验证售价不能低于成本价
        if ($validated['sale_price'] < $validated['cost_price']) {
            return response()->json([
                'message' => '售价不能低于成本价',
            ], 422);
        }

        // 验证已售库存不能超过总库存
        $stockTotal = $validated['stock_total'] ?? 0;
        $stockSold = $validated['stock_sold'] ?? 0;
        if ($stockSold > $stockTotal) {
            return response()->json([
                'message' => '已售库存不能超过总库存',
            ], 422);
        }

        // 设置默认值
        $validated['stock_total'] = $stockTotal;
        $validated['stock_sold'] = $stockSold;
        $validated['source'] = PriceSource::MANUAL->value;
        $validated['version'] = 0;

        $stock = ResHotelDailyStock::create($validated);
        $stock->load(['hotel.scenicSpot', 'roomType']);

        return response()->json([
            'data' => $stock,
            'message' => '价库记录创建成功',
        ], 201);
    }

    /**
     * 批量创建价库记录
     */
    public function batchStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => 'required|exists:res_hotels,id',
            'room_type_id' => 'required|exists:res_room_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'cost_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0',
            'stock_total' => 'nullable|integer|min:0',
            'stock_sold' => 'nullable|integer|min:0',
        ]);

        // 验证日期范围合理性（不超过1年）
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        if ($startDate->diffInDays($endDate) > 365) {
            return response()->json([
                'message' => '批量设置的日期范围不能超过365天',
            ], 422);
        }

        // 验证房型是否属于指定酒店
        $roomType = ResRoomType::findOrFail($validated['room_type_id']);
        if ($roomType->hotel_id != $validated['hotel_id']) {
            return response()->json([
                'message' => '房型不属于指定的酒店',
            ], 422);
        }

        // 验证售价不能低于成本价
        if ($validated['sale_price'] < $validated['cost_price']) {
            return response()->json([
                'message' => '售价不能低于成本价',
            ], 422);
        }

        // 验证已售库存不能超过总库存
        $stockTotal = $validated['stock_total'] ?? 0;
        $stockSold = $validated['stock_sold'] ?? 0;
        if ($stockSold > $stockTotal) {
            return response()->json([
                'message' => '已售库存不能超过总库存',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $createdCount = 0;
            $updatedCount = 0;
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $stock = ResHotelDailyStock::updateOrCreate(
                    [
                        'room_type_id' => $validated['room_type_id'],
                        'biz_date' => $currentDate->format('Y-m-d'),
                    ],
                    [
                        'hotel_id' => $validated['hotel_id'],
                        'cost_price' => $validated['cost_price'],
                        'sale_price' => $validated['sale_price'],
                        'stock_total' => $stockTotal,
                        'stock_sold' => $stockSold,
                        'source' => PriceSource::MANUAL->value,
                        'version' => 0,
                    ]
                );

                if ($stock->wasRecentlyCreated) {
                    $createdCount++;
                } else {
                    // 只更新手工维护的数据
                    if ($stock->source === PriceSource::MANUAL) {
                        $stock->update([
                            'cost_price' => $validated['cost_price'],
                            'sale_price' => $validated['sale_price'],
                            'stock_total' => $stockTotal,
                            'stock_sold' => $stockSold,
                        ]);
                        $updatedCount++;
                    }
                }

                $currentDate->addDay();
            }

            DB::commit();

            return response()->json([
                'message' => '批量设置成功',
                'data' => [
                    'created_count' => $createdCount,
                    'updated_count' => $updatedCount,
                    'total_days' => $startDate->diffInDays($endDate) + 1,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => '批量设置失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 更新价库记录
     */
    public function update(Request $request, ResHotelDailyStock $resHotelDailyStock): JsonResponse
    {
        // 接口推送的数据不允许人工修改
        if ($resHotelDailyStock->source === PriceSource::API) {
            return response()->json([
                'message' => '接口推送的价库数据不允许人工修改',
            ], 403);
        }

        $validated = $request->validate([
            'cost_price' => 'sometimes|required|numeric|min:0',
            'sale_price' => 'sometimes|required|numeric|min:0',
            'stock_total' => 'nullable|integer|min:0',
            'stock_sold' => 'nullable|integer|min:0',
        ]);

        // 验证售价不能低于成本价
        $costPrice = $validated['cost_price'] ?? $resHotelDailyStock->cost_price;
        $salePrice = $validated['sale_price'] ?? $resHotelDailyStock->sale_price;
        if ($salePrice < $costPrice) {
            return response()->json([
                'message' => '售价不能低于成本价',
            ], 422);
        }

        // 验证已售库存不能超过总库存
        $stockTotal = $validated['stock_total'] ?? $resHotelDailyStock->stock_total;
        $stockSold = $validated['stock_sold'] ?? $resHotelDailyStock->stock_sold;
        if ($stockSold > $stockTotal) {
            return response()->json([
                'message' => '已售库存不能超过总库存',
            ], 422);
        }

        $resHotelDailyStock->update($validated);
        $resHotelDailyStock->load(['hotel.scenicSpot', 'roomType']);

        return response()->json([
            'data' => $resHotelDailyStock,
            'message' => '价库记录更新成功',
        ]);
    }

    /**
     * 删除价库记录
     */
    public function destroy(ResHotelDailyStock $resHotelDailyStock): JsonResponse
    {
        // 接口推送的数据不允许删除
        if ($resHotelDailyStock->source === PriceSource::API) {
            return response()->json([
                'message' => '接口推送的价库数据不允许删除',
            ], 403);
        }

        $resHotelDailyStock->delete();

        return response()->json([
            'message' => '价库记录删除成功',
        ]);
    }

    /**
     * 关闭价库（人工关闭）
     */
    public function close(ResHotelDailyStock $resHotelDailyStock): JsonResponse
    {
        // 接口推送的数据不允许关闭
        if ($resHotelDailyStock->source === PriceSource::API) {
            return response()->json([
                'message' => '接口推送的价库数据不允许关闭',
            ], 403);
        }

        $resHotelDailyStock->update(['is_closed' => true]);

        return response()->json([
            'message' => '价库已关闭',
        ]);
    }

    /**
     * 开启价库
     */
    public function open(ResHotelDailyStock $resHotelDailyStock): JsonResponse
    {
        // 接口推送的数据不允许开启
        if ($resHotelDailyStock->source === PriceSource::API) {
            return response()->json([
                'message' => '接口推送的价库数据不允许开启',
            ], 403);
        }

        $resHotelDailyStock->update(['is_closed' => false]);

        return response()->json([
            'message' => '价库已开启',
        ]);
    }
}
