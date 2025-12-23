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
            $prices[] = Price::updateOrCreate(
                [
                    'product_id' => $validated['product_id'],
                    'room_type_id' => $validated['room_type_id'],
                    'date' => $priceData['date'],
                ],
                array_merge($priceData, [
                    'source' => PriceSource::MANUAL,
                ])
            );
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
