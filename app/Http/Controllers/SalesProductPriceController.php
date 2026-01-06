<?php

namespace App\Http\Controllers;

use App\Models\SalesProduct;
use App\Models\SalesProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 销售产品价格日历管理控制器
 */
class SalesProductPriceController extends Controller
{
    /**
     * 价格日历列表
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'sales_product_id' => 'required|exists:sales_products,id',
        ]);

        $salesProduct = SalesProduct::find($request->sales_product_id);
        
        // 权限控制
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权查看该产品的价格日历',
                ], 403);
            }
        }

        $query = SalesProductPrice::where('sales_product_id', $request->sales_product_id);

        // 确定日期范围
        $today = now()->format('Y-m-d');
        $saleStartDate = $salesProduct->sale_start_date->format('Y-m-d');
        $saleEndDate = $salesProduct->sale_end_date->format('Y-m-d');
        
        // 计算开始日期：取今天和销售开始日期的较大值
        $startDate = max($today, $saleStartDate);
        
        // 计算结束日期：取销售结束日期和开始日期+60天的较小值
        $maxEndDate = date('Y-m-d', strtotime($startDate . ' +60 days'));
        $endDate = min($saleEndDate, $maxEndDate);
        
        // 确保不超过60天
        $calculatedDays = (strtotime($endDate) - strtotime($startDate)) / 86400;
        if ($calculatedDays > 60) {
            $endDate = date('Y-m-d', strtotime($startDate . ' +60 days'));
        }
        
        // 如果开始日期晚于结束日期，不显示任何数据
        if ($startDate > $endDate) {
            return response()->json([
                'data' => [],
                'total' => 0,
                'current_page' => 1,
                'per_page' => 30,
                'last_page' => 1,
            ]);
        }
        
        // 只显示在计算出的日期范围内的价格
        $query->where('date', '>=', $startDate)
              ->where('date', '<=', $endDate);
        
        // 如果前端传入了日期范围，使用前端传入的范围（但不超过计算出的范围）
        if ($request->has('date_from')) {
            $dateFrom = max($request->date_from, $startDate);
            $query->where('date', '>=', $dateFrom);
        }
        
        if ($request->has('date_to')) {
            $dateTo = min($request->date_to, $endDate);
            $query->where('date', '<=', $dateTo);
        }

        $prices = $query->orderBy('date', 'asc')
            ->paginate($request->get('per_page', 30));

        return response()->json($prices);
    }

    /**
     * 手动触发价格更新
     */
    public function updatePriceCalendar(Request $request): JsonResponse
    {
        $request->validate([
            'sales_product_id' => 'required|exists:sales_products,id',
        ]);

        $salesProduct = SalesProduct::find($request->sales_product_id);
        
        // 权限控制
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($salesProduct->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该产品的价格日历',
                ], 403);
            }
        }

        // 异步更新价格日历
        \App\Jobs\UpdateSystemPkgPriceJob::dispatch($salesProduct->id);

        return response()->json([
            'message' => '价格日历更新任务已提交，将在后台处理',
        ]);
    }
}

