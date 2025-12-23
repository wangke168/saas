<?php

namespace App\Http\Controllers;

use App\Models\PriceRule;
use App\Enums\PriceRuleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PriceRuleController extends Controller
{
    /**
     * 加价规则列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = PriceRule::with(['product', 'items.hotel', 'items.roomType']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $rules = $query->paginate($request->get('per_page', 15));

        return response()->json($rules);
    }

    /**
     * 创建加价规则
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'type' => ['required', 'in:' . implode(',', array_column(PriceRuleType::cases(), 'value'))],
            'weekdays' => 'required_if:type,weekday|string',
            'start_date' => 'required_if:type,date_range|date',
            'end_date' => 'required_if:type,date_range|date|after_or_equal:start_date',
            'market_price_adjustment' => 'required|numeric',
            'settlement_price_adjustment' => 'required|numeric',
            'sale_price_adjustment' => 'required|numeric',
            'is_active' => 'boolean',
            'items' => 'required|array',
            'items.*.hotel_id' => 'required|exists:hotels,id',
            'items.*.room_type_id' => 'required|exists:room_types,id',
        ]);

        $rule = DB::transaction(function () use ($validated) {
            $rule = PriceRule::create([
                'product_id' => $validated['product_id'],
                'name' => $validated['name'],
                'type' => $validated['type'],
                'weekdays' => $validated['weekdays'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'market_price_adjustment' => $validated['market_price_adjustment'],
                'settlement_price_adjustment' => $validated['settlement_price_adjustment'],
                'sale_price_adjustment' => $validated['sale_price_adjustment'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            foreach ($validated['items'] as $item) {
                $rule->items()->create([
                    'hotel_id' => $item['hotel_id'],
                    'room_type_id' => $item['room_type_id'],
                ]);
            }

            return $rule->load(['product', 'items.hotel', 'items.roomType']);
        });

        return response()->json([
            'message' => '加价规则创建成功',
            'data' => $rule,
        ], 201);
    }

    /**
     * 加价规则详情
     */
    public function show(PriceRule $priceRule): JsonResponse
    {
        $priceRule->load(['product', 'items.hotel', 'items.roomType']);
        
        return response()->json([
            'data' => $priceRule,
        ]);
    }

    /**
     * 更新加价规则
     */
    public function update(Request $request, PriceRule $priceRule): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', 'in:' . implode(',', array_column(PriceRuleType::cases(), 'value'))],
            'weekdays' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'market_price_adjustment' => 'sometimes|numeric',
            'settlement_price_adjustment' => 'sometimes|numeric',
            'sale_price_adjustment' => 'sometimes|numeric',
            'is_active' => 'sometimes|boolean',
            'items' => 'sometimes|array',
            'items.*.hotel_id' => 'required|exists:hotels,id',
            'items.*.room_type_id' => 'required|exists:room_types,id',
        ]);

        DB::transaction(function () use ($priceRule, $validated) {
            $priceRule->update($validated);

            if (isset($validated['items'])) {
                $priceRule->items()->delete();
                foreach ($validated['items'] as $item) {
                    $priceRule->items()->create([
                        'hotel_id' => $item['hotel_id'],
                        'room_type_id' => $item['room_type_id'],
                    ]);
                }
            }
        });

        $priceRule->load(['product', 'items.hotel', 'items.roomType']);

        return response()->json([
            'message' => '加价规则更新成功',
            'data' => $priceRule,
        ]);
    }

    /**
     * 删除加价规则
     */
    public function destroy(PriceRule $priceRule): JsonResponse
    {
        $priceRule->delete();

        return response()->json([
            'message' => '加价规则删除成功',
        ]);
    }
}
