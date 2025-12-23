<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\OTA\CtripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CtripSyncController extends Controller
{
    public function __construct(
        protected CtripService $ctripService
    ) {}

    /**
     * 同步产品价格到携程
     */
    public function syncPrice(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'dates' => 'nullable|array',
            'dates.*' => 'date',
            'date_type' => 'nullable|in:DATE_REQUIRED,DATE_NOT_REQUIRED',
        ]);

        try {
            $dates = $request->input('dates');
            $dateType = $request->input('date_type', 'DATE_REQUIRED');

            $result = $this->ctripService->syncProductPrice($product, $dates, $dateType);

            if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
                return response()->json([
                    'success' => true,
                    'message' => '价格同步成功',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['header']['resultMessage'] ?? '价格同步失败',
                'data' => $result,
            ], 400);
        } catch (\Exception $e) {
            Log::error('同步价格到携程失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '同步失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 同步产品库存到携程
     */
    public function syncStock(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'dates' => 'nullable|array',
            'dates.*' => 'date',
            'date_type' => 'nullable|in:DATE_REQUIRED,DATE_NOT_REQUIRED',
        ]);

        try {
            $dates = $request->input('dates');
            $dateType = $request->input('date_type', 'DATE_REQUIRED');

            $result = $this->ctripService->syncProductStock($product, $dates, $dateType);

            if (isset($result['header']['resultCode']) && $result['header']['resultCode'] === '0000') {
                return response()->json([
                    'success' => true,
                    'message' => '库存同步成功',
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['header']['resultMessage'] ?? '库存同步失败',
                'data' => $result,
            ], 400);
        } catch (\Exception $e) {
            Log::error('同步库存到携程失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '同步失败：' . $e->getMessage(),
            ], 500);
        }
    }
}

