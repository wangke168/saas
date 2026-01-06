<?php

namespace App\Http\Controllers;

use App\Models\Pkg\PkgProduct;
use App\Services\Pkg\PkgProductPriceService;
use App\Jobs\Pkg\CalculateProductDailyPricesJob;
use App\Jobs\Pkg\SyncProductPricesToOtaJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PkgProductPriceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        // 权限控制：所有方法都需要操作员或管理员权限
        $this->middleware('role:admin,operator');
    }

    /**
     * 预计算产品价格
     */
    public function calculate(PkgProduct $pkgProduct, PkgProductPriceService $service): JsonResponse
    {
        try {
            // 异步执行价格计算
            CalculateProductDailyPricesJob::dispatch($pkgProduct->id);

            return response()->json([
                'message' => '价格预计算任务已提交，正在后台处理',
            ]);
        } catch (\Exception $e) {
            Log::error('提交价格预计算任务失败', [
                'product_id' => $pkgProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => '提交价格预计算任务失败: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 重新计算产品价格
     */
    public function recalculate(PkgProduct $pkgProduct, PkgProductPriceService $service): JsonResponse
    {
        try {
            // 异步执行价格重新计算
            CalculateProductDailyPricesJob::dispatch($pkgProduct->id);

            return response()->json([
                'message' => '价格重新计算任务已提交，正在后台处理',
            ]);
        } catch (\Exception $e) {
            Log::error('提交价格重新计算任务失败', [
                'product_id' => $pkgProduct->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => '提交价格重新计算任务失败: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 推送价格到OTA
     */
    public function syncToOta(PkgProduct $pkgProduct, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_code' => 'required|string|in:ctrip,meituan',
        ]);

        // TODO: 实现OTA价格推送功能
        // 这将在下一个任务中实现（PkgProductOtaSyncService）

        return response()->json([
            'message' => 'OTA价格推送功能待实现',
        ], 501);
    }

    /**
     * 获取价格日历（用于前端展示）
     */
    public function getPriceCalendar(PkgProduct $pkgProduct, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'hotel_id' => 'nullable|exists:res_hotels,id',
            'room_type_id' => 'nullable|exists:res_room_types,id',
        ]);

        $query = $pkgProduct->dailyPrices()->with(['hotel', 'roomType']);

        if (isset($validated['start_date'])) {
            $query->where('biz_date', '>=', $validated['start_date']);
        }

        if (isset($validated['end_date'])) {
            $query->where('biz_date', '<=', $validated['end_date']);
        }

        if (isset($validated['hotel_id'])) {
            $query->where('hotel_id', $validated['hotel_id']);
        }

        if (isset($validated['room_type_id'])) {
            $query->where('room_type_id', $validated['room_type_id']);
        }

        $prices = $query->orderBy('biz_date')->orderBy('hotel_id')->orderBy('room_type_id')->get();

        return response()->json([
            'data' => $prices,
        ]);
    }
}

