<?php

namespace App\Http\Controllers;

use App\Models\Price;
use App\Enums\PriceSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceController extends Controller
{
    /**
     * 价格列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Price::with(['product', 'roomType']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('room_type_id')) {
            $query->where('room_type_id', $request->room_type_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        $prices = $query->orderBy('date')->paginate($request->get('per_page', 15));

        return response()->json($prices);
    }

    /**
     * 批量创建价格
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'room_type_id' => 'required|exists:room_types,id',
            'prices' => 'required|array',
            'prices.*.date' => 'required|date',
            'prices.*.market_price' => 'required|numeric|min:0',
            'prices.*.settlement_price' => 'required|numeric|min:0',
            'prices.*.sale_price' => 'required|numeric|min:0',
        ]);

        $prices = [];
        foreach ($validated['prices'] as $priceData) {
            // 先检查是否存在（包括软删除的记录）
            // 因为唯一约束不考虑 deleted_at，如果存在软删除的记录，直接插入会冲突
            $price = Price::withTrashed()
                ->where('product_id', $validated['product_id'])
                ->where('room_type_id', $validated['room_type_id'])
                ->where('date', $priceData['date'])
                ->first();

            if ($price) {
                // 如果存在（包括软删除的），恢复并更新
                if ($price->trashed()) {
                    $price->restore();
                }
                $price->update(array_merge($priceData, [
                    'source' => PriceSource::MANUAL,
                ]));
                $prices[] = $price;
            } else {
                // 如果不存在，创建新记录
                $prices[] = Price::create(array_merge($priceData, [
                    'product_id' => $validated['product_id'],
                    'room_type_id' => $validated['room_type_id'],
                    'source' => PriceSource::MANUAL,
                ]));
            }
        }

        return response()->json([
            'message' => '价格创建成功',
            'data' => $prices,
        ], 201);
    }

    /**
     * 更新价格
     */
    public function update(Request $request, Price $price): JsonResponse
    {
        $validated = $request->validate([
            'market_price' => 'sometimes|numeric|min:0',
            'settlement_price' => 'sometimes|numeric|min:0',
            'sale_price' => 'sometimes|numeric|min:0',
        ]);

        $price->update($validated);
        $price->load(['product', 'roomType']);

        return response()->json([
            'message' => '价格更新成功',
            'data' => $price,
        ]);
    }

    /**
     * 删除价格
     */
    public function destroy(Price $price): JsonResponse
    {
        $price->delete();

        return response()->json([
            'message' => '价格删除成功',
        ]);
    }
}
