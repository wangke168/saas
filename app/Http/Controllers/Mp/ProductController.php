<?php

namespace App\Http\Controllers\Mp;

use App\Models\OrderEntitlement;
use App\Models\Product;
use App\Models\ProductHotelRelation;
use App\Services\Mp\MpAuthService;
use App\Support\ProductMpPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends BaseMpController
{
    public function __construct(
        private readonly MpAuthService $mpAuthService,
    ) {}

    public function show(Product $product, Request $request): JsonResponse
    {
        $phone = $this->resolvePhone($request, $this->mpAuthService);
        if ($phone === null) {
            return $this->unauthorized();
        }

        $owned = OrderEntitlement::query()
            ->where('product_id', $product->id)
            ->whereHas('order', function ($query) use ($phone) {
                $query->where('contact_phone', $phone);
            })
            ->exists();
        if (!$owned) {
            return response()->json(['message' => '无权限查看该产品'], 403);
        }

        $hotelCount = ProductHotelRelation::query()
            ->where('ticket_product_id', $product->id)
            ->where('is_active', true)
            ->distinct('hotel_id')
            ->count('hotel_id');

        return response()->json([
            'message' => 'success',
            'data' => array_merge([
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'sale_start_date' => $product->sale_start_date?->format('Y-m-d'),
                'sale_end_date' => $product->sale_end_date?->format('Y-m-d'),
                'stay_days' => $product->stay_days,
                'fulfillment_mode' => $product->fulfillment_mode,
                'booking_advance_days' => max(0, (int) ($product->booking_advance_days ?? 0)),
                'booking_advance_hint' => $product->bookingAdvanceHint(),
                'available_hotel_count' => $hotelCount,
            ], ProductMpPayload::forMp($product)),
        ]);
    }
}

