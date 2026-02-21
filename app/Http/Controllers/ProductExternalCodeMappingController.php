<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductExternalCodeMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductExternalCodeMappingController extends Controller
{
    /**
     * 获取产品的外部编码映射列表
     */
    public function index(Request $request, Product $product): JsonResponse
    {
        // 权限控制：检查是否有权限查看该产品
        $this->authorize('view', $product);

        $mappings = $product->externalCodeMappings()
            ->orderBy('start_date')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $mappings,
        ]);
    }

    /**
     * 创建外部编码映射
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        // 权限控制：检查是否有权限修改该产品
        $this->authorize('update', $product);

        $validated = $request->validate([
            'external_code' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        // 检查时间段重叠
        if (ProductExternalCodeMapping::hasOverlap(
            $product->id,
            $validated['start_date'],
            $validated['end_date']
        )) {
            throw ValidationException::withMessages([
                'start_date' => '该时间段与现有映射重叠，请选择其他时间段',
                'end_date' => '该时间段与现有映射重叠，请选择其他时间段',
            ]);
        }

        $mapping = $product->externalCodeMappings()->create([
            'external_code' => $validated['external_code'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        Log::info('创建产品外部编码映射', [
            'product_id' => $product->id,
            'mapping_id' => $mapping->id,
            'external_code' => $mapping->external_code,
            'start_date' => $mapping->start_date,
            'end_date' => $mapping->end_date,
        ]);

        return response()->json([
            'message' => '外部编码映射创建成功',
            'data' => $mapping,
        ], 201);
    }

    /**
     * 更新外部编码映射
     */
    public function update(
        Request $request,
        Product $product,
        ProductExternalCodeMapping $productExternalCodeMapping
    ): JsonResponse {
        $mapping = $productExternalCodeMapping;
        // 权限控制：检查是否有权限修改该产品
        $this->authorize('update', $product);

        // 验证映射属于该产品
        if ($mapping->product_id !== $product->id) {
            return response()->json([
                'message' => '映射不属于该产品',
            ], 403);
        }

        $validated = $request->validate([
            'external_code' => 'sometimes|required|string|max:255',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        // 如果更新了日期，检查时间段重叠（排除自身）
        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $startDate = $validated['start_date'] ?? $mapping->start_date->format('Y-m-d');
            $endDate = $validated['end_date'] ?? $mapping->end_date->format('Y-m-d');

            if (ProductExternalCodeMapping::hasOverlap(
                $product->id,
                $startDate,
                $endDate,
                $mapping->id
            )) {
                throw ValidationException::withMessages([
                    'start_date' => '该时间段与现有映射重叠，请选择其他时间段',
                    'end_date' => '该时间段与现有映射重叠，请选择其他时间段',
                ]);
            }
        }

        $mapping->update($validated);

        Log::info('更新产品外部编码映射', [
            'product_id' => $product->id,
            'mapping_id' => $mapping->id,
            'external_code' => $mapping->external_code,
            'start_date' => $mapping->start_date,
            'end_date' => $mapping->end_date,
        ]);

        return response()->json([
            'message' => '外部编码映射更新成功',
            'data' => $mapping->fresh(),
        ]);
    }

    /**
     * 删除外部编码映射
     */
    public function destroy(
        Product $product,
        ProductExternalCodeMapping $productExternalCodeMapping
    ): JsonResponse {
        $mapping = $productExternalCodeMapping;
        // 权限控制：检查是否有权限修改该产品
        $this->authorize('update', $product);

        // 验证映射属于该产品
        if ($mapping->product_id !== $product->id) {
            return response()->json([
                'message' => '映射不属于该产品',
            ], 403);
        }

        $mapping->delete();

        Log::info('删除产品外部编码映射', [
            'product_id' => $product->id,
            'mapping_id' => $mapping->id,
        ]);

        return response()->json([
            'message' => '外部编码映射删除成功',
        ]);
    }

    /**
     * 获取单个外部编码映射详情
     */
    public function show(
        Product $product,
        ProductExternalCodeMapping $productExternalCodeMapping
    ): JsonResponse {
        $mapping = $productExternalCodeMapping;
        // 权限控制：检查是否有权限查看该产品
        $this->authorize('view', $product);

        // 验证映射属于该产品
        if ($mapping->product_id !== $product->id) {
            return response()->json([
                'message' => '映射不属于该产品',
            ], 403);
        }

        return response()->json([
            'data' => $mapping,
        ]);
    }
}
