<?php

namespace App\Support;

use App\Models\Product;

final class ProductMpPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(Product $product): array
    {
        return [
            'cover_image' => $product->cover_image,
            'cover_image_url' => PublicMedia::url($product->cover_image),
            'booking_rules' => $product->booking_rules ?? [],
            'mp_content' => $product->mp_content,
            'fee_note' => $product->fee_note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forMp(Product $product): array
    {
        return array_merge([
            'cover_image_url' => PublicMedia::url($product->cover_image),
            'booking_rules' => $product->resolvedBookingRules(),
            'mp_content' => $product->mp_content,
            'fee_note' => $product->resolvedFeeNote(),
        ], ProductIdRegionRestriction::payloadForMp($product));
    }
}
