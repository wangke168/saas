<?php

namespace App\Http\Controllers;

use App\Enums\OtaPlatform;
use App\Models\OtaProduct;
use App\Models\Product;
use App\Services\OTA\CtripService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * 产品列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['scenicSpot']);

        // 权限控制：运营只能查看自己绑定的景区下的产品
        if ($request->user()->isOperator()) {
            $scenicSpotIds = $request->user()->scenicSpots->pluck('id');
            $query->whereIn('scenic_spot_id', $scenicSpotIds);
        }

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('scenic_spot_id')) {
            $query->where('scenic_spot_id', $request->scenic_spot_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $products = $query->paginate($request->get('per_page', 15));

        return response()->json($products);
    }

    /**
     * 产品详情
     */
    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        // 加载关联数据，使用 try-catch 处理可能的关联缺失问题
        try {
            // 先加载基本关联
            $product->load('scenicSpot');
            
            // 加载价格及其关联（如果存在）
            $product->load(['prices' => function ($query) {
                $query->with(['roomType' => function ($q) {
                    $q->with('hotel');
                }]);
            }]);
            
            // 加载加价规则及其关联（如果存在）
            $product->load(['priceRules' => function ($query) {
                $query->with(['items' => function ($q) {
                    $q->with(['hotel', 'roomType']);
                }]);
            }]);
            
            // 加载OTA产品关联（如果存在）
            $product->load(['otaProducts' => function ($query) {
                $query->with('otaPlatform');
            }]);
        } catch (\Exception $e) {
            // 如果关联加载失败，记录日志但继续返回数据
            \Log::warning('加载产品关联数据失败', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            
            // 尝试只加载基本关联
            $product->load(['scenicSpot', 'prices', 'priceRules', 'otaProducts']);
        }
        
        return response()->json([
            'data' => $product,
        ]);
    }

    /**
     * 创建产品
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:products,code',
            'description' => 'nullable|string',
            'price_source' => 'sometimes|in:manual,api',
            'stay_days' => 'nullable|integer|min:1|max:30',
            'sale_start_date' => 'required|date',
            'sale_end_date' => 'required|date|after_or_equal:sale_start_date',
            'is_active' => 'boolean',
        ]);

        // 权限控制：使用 Policy 检查创建权限（传入景区ID）
        $policy = app(\App\Policies\ProductPolicy::class);
        if (! $policy->create($request->user(), $validated['scenic_spot_id'])) {
            abort(403, '无权在该景区下创建产品');
        }

        // 确保 stay_days 为空时转换为 null
        if (isset($validated['stay_days']) && ($validated['stay_days'] === '' || $validated['stay_days'] === 0)) {
            $validated['stay_days'] = null;
        }

        // 确保销售日期为空字符串时转换为 null
        if (isset($validated['sale_start_date']) && $validated['sale_start_date'] === '') {
            $validated['sale_start_date'] = null;
        }
        if (isset($validated['sale_end_date']) && $validated['sale_end_date'] === '') {
            $validated['sale_end_date'] = null;
        }

        $product = $this->productService->createProduct($validated);

        return response()->json([
            'message' => '产品创建成功',
            'data' => $product,
        ], 201);
    }

    /**
     * 更新产品
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'sometimes|required|exists:scenic_spots,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'required', 'string', 'max:255', 'unique:products,code,' . $product->id],
            'description' => 'nullable|string',
            'price_source' => 'sometimes|in:manual,api',
            'stay_days' => 'nullable|integer|min:1|max:30',
            'sale_start_date' => 'required|date',
            'sale_end_date' => 'required|date|after_or_equal:sale_start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        // 权限控制：使用 Policy 检查更新权限（包括景区变更的权限）
        $newScenicSpotId = $validated['scenic_spot_id'] ?? null;
        $policy = app(\App\Policies\ProductPolicy::class);
        if (! $policy->update($request->user(), $product, $newScenicSpotId)) {
            abort(403, '无权更新该产品');
        }

        // 确保 stay_days 为空时转换为 null
        if (isset($validated['stay_days']) && ($validated['stay_days'] === '' || $validated['stay_days'] === 0)) {
            $validated['stay_days'] = null;
        }

        // 确保销售日期为空字符串时转换为 null
        if (isset($validated['sale_start_date']) && $validated['sale_start_date'] === '') {
            $validated['sale_start_date'] = null;
        }
        if (isset($validated['sale_end_date']) && $validated['sale_end_date'] === '') {
            $validated['sale_end_date'] = null;
        }

        $product = $this->productService->updateProduct($product, $validated);

        return response()->json([
            'message' => '产品更新成功',
            'data' => $product,
        ]);
    }

    /**
     * 删除产品（软删除）
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->json([
            'message' => '产品删除成功',
        ]);
    }

    /**
     * 导出产品到Excel（只导出已推送到携程的产品）
     */
    public function export(Request $request, Product $product, CtripService $ctripService): Response
    {
        // 权限控制：检查是否有权限查看该产品
        $this->authorize('view', $product);

        // 获取携程平台
        $ctripPlatform = \App\Models\OtaPlatform::where('code', OtaPlatform::CTRIP->value)->first();
        if (!$ctripPlatform) {
            abort(404, '携程平台不存在');
        }

        // 检查产品是否已推送到携程
        $otaProduct = $product->otaProducts()
            ->where('ota_platform_id', $ctripPlatform->id)
            ->where('is_active', true)
            ->first();

        if (!$otaProduct) {
            abort(404, '该产品未推送到携程，无法导出');
        }

        // 加载产品的关联数据
        $product->load(['prices.roomType.hotel']);

        // 只导出该产品
        $products = collect([$product]);

        // 构建导出数据
        $exportData = [];
        foreach ($products as $product) {
            // 获取产品的所有"产品-酒店-房型"组合
            $prices = $product->prices()->with(['roomType.hotel'])->get();
            $seen = [];

            foreach ($prices as $price) {
                $roomType = $price->roomType;
                if (!$roomType) {
                    continue;
                }

                $hotel = $roomType->hotel;
                if (!$hotel) {
                    continue;
                }

                // 检查编码
                if (empty($product->code) || empty($hotel->code) || empty($roomType->code)) {
                    continue;
                }

                $key = "{$hotel->id}_{$roomType->id}";
                if (!isset($seen[$key])) {
                    // 生成携程PLU编号
                    $ctripPlu = $ctripService->generateCtripProductCode(
                        $product->code,
                        $hotel->code,
                        $roomType->code
                    );

                    $exportData[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'hotel_name' => $hotel->name,
                        'room_type_name' => $roomType->name,
                        'ctrip_plu' => $ctripPlu,
                    ];

                    $seen[$key] = true;
                }
            }
        }

        // 生成CSV内容（可以重命名为.xlsx，Excel也能打开）
        $filename = 'product_' . $product->code . '_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        // 添加BOM以支持Excel正确显示中文
        $output = "\xEF\xBB\xBF";

        // 写入表头
        $output .= "产品ID,产品名称,酒店名称,房型名称,携程PLU编号\n";

        // 写入数据
        foreach ($exportData as $row) {
            $output .= sprintf(
                "%s,%s,%s,%s,%s\n",
                $row['product_id'],
                $this->escapeCsvField($row['product_name']),
                $this->escapeCsvField($row['hotel_name']),
                $this->escapeCsvField($row['room_type_name']),
                $row['ctrip_plu']
            );
        }

        return response($output, 200, $headers);
    }

    /**
     * 转义CSV字段（处理包含逗号、引号等特殊字符的情况）
     */
    protected function escapeCsvField(string $field): string
    {
        // 如果字段包含逗号、引号或换行符，需要用引号包裹，并转义引号
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }
}
