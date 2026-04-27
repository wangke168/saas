<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProductRoomInventoryControlBatchRequest;
use App\Models\Product;
use App\Services\ProductRoomInventoryControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductRoomInventoryControlController extends Controller
{
    public function __construct(
        private readonly ProductRoomInventoryControlService $controlService
    ) {}

    public function index(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'hotel_id' => ['nullable', 'integer', 'exists:hotels,id'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $data = $this->controlService->paginateByProduct(
            $product,
            $validated,
            (int) ($validated['per_page'] ?? 30)
        );

        return response()->json($data);
    }

    public function batchClose(ProductRoomInventoryControlBatchRequest $request, Product $product): JsonResponse
    {
        $count = $this->controlService->batchClose(
            $product,
            $request->validated(),
            (int) $request->user()->id
        );

        return response()->json([
            'message' => '产品库存已批量关闭',
            'affected_records' => $count,
        ]);
    }

    public function batchOpen(ProductRoomInventoryControlBatchRequest $request, Product $product): JsonResponse
    {
        $count = $this->controlService->batchOpen($product, $request->validated());

        return response()->json([
            'message' => '产品库存已批量开启',
            'affected_records' => $count,
        ]);
    }
}
