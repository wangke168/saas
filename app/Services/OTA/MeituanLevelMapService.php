<?php

namespace App\Services\OTA;

use App\Models\Hotel;
use App\Models\MeituanLevelMap;
use App\Models\Price;
use App\Models\Product;
use App\Models\RoomType;
use App\Services\ProductService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MeituanLevelMapService
{
    private const UNIT_PRICE_MATCH_TOLERANCE = 0.01;

    public function __construct(
        protected ProductService $productService,
    ) {}

    /**
     * @param  array<int, mixed>  $levelIds
     */
    public function rememberFromMatchedSku(
        Product $product,
        Hotel $hotel,
        RoomType $roomType,
        array $levelIds,
    ): void {
        $parsed = $this->parseLevelIds($levelIds);
        if ($parsed === null) {
            return;
        }

        $scenicSpotId = (int) $product->scenic_spot_id;

        $this->upsertMap(
            $scenicSpotId,
            (int) $product->id,
            $parsed['hotel_level_id'],
            MeituanLevelMap::LEVEL_HOTEL,
            (int) $hotel->id,
            (string) $hotel->name,
        );

        $this->upsertMap(
            $scenicSpotId,
            (int) $product->id,
            $parsed['room_level_id'],
            MeituanLevelMap::LEVEL_ROOM,
            null,
            (string) $roomType->name,
        );
    }

    /**
     * @param  Collection<int, Price>  $prices
     * @param  array<int, mixed>  $levelIds
     * @return array{
     *     price: Price,
     *     resolve_method: string,
     *     skip_inventory: bool,
     *     create_exception: bool,
     *     sku_confirmed: bool,
     *     candidates: list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>
     * }
     */
    public function resolveWithoutPartnerPrimaryKey(
        Product $product,
        Collection $prices,
        string $useDate,
        float $unitPrice,
        array $levelIds = [],
    ): array {
        $candidates = $this->buildCandidates($product, $prices, $useDate);
        $parsed = $this->parseLevelIds($levelIds);

        $hotelMap = null;
        $roomMap = null;
        if ($parsed !== null) {
            $hotelMap = $this->findMap((int) $product->id, $parsed['hotel_level_id']);
            $roomMap = $this->findMap((int) $product->id, $parsed['room_level_id']);
        }

        if ($hotelMap !== null && $roomMap !== null && $hotelMap->level_no === MeituanLevelMap::LEVEL_HOTEL) {
            $comboMatches = array_values(array_filter(
                $candidates,
                fn (array $row): bool => $row['hotel_id'] === (int) $hotelMap->hotel_id
                    && $row['room_type_name'] === $roomMap->level_name,
            ));
            if (count($comboMatches) === 1) {
                return $this->buildResult($prices, $comboMatches[0], 'level_ids_combo', false, false, true, $candidates);
            }
        }

        if ($roomMap !== null && $roomMap->level_no === MeituanLevelMap::LEVEL_ROOM) {
            $namePriceMatches = $this->filterByRoomNameAndPrice($candidates, $roomMap->level_name, $unitPrice);
            if (count($namePriceMatches) === 1) {
                return $this->buildResult($prices, $namePriceMatches[0], 'level_name_price', false, false, true, $candidates);
            }
        }

        $priceMatches = $this->filterByUnitPrice($candidates, $unitPrice);
        if (count($priceMatches) === 1) {
            return $this->buildResult($prices, $priceMatches[0], 'unit_price_unique', false, true, true, $candidates);
        }

        $placeholder = $priceMatches[0] ?? $candidates[0] ?? null;
        if ($placeholder === null) {
            $fallbackPrice = $prices->first();
            if ($fallbackPrice === null) {
                throw new \RuntimeException('产品指定日期没有可用价格行');
            }

            return [
                'price' => $fallbackPrice,
                'resolve_method' => 'first_price_fallback',
                'skip_inventory' => true,
                'create_exception' => true,
                'sku_confirmed' => false,
                'candidates' => $candidates,
            ];
        }

        return $this->buildResult($prices, $placeholder, 'first_price_fallback', true, true, false, $candidates);
    }

    /**
     * @param  list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>  $candidates
     * @return list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>
     */
    public function filterByRoomNameAndPrice(array $candidates, string $roomTypeName, float $unitPrice): array
    {
        $target = round($unitPrice, 2);

        return array_values(array_filter(
            $candidates,
            fn (array $row): bool => $row['room_type_name'] === $roomTypeName
                && abs($row['sale_price'] - $target) <= self::UNIT_PRICE_MATCH_TOLERANCE,
        ));
    }

    public function upsertLevel(
        Product $product,
        int $levelId,
        int $levelNo,
        ?int $hotelId,
        string $levelName,
    ): void {
        $this->upsertMap(
            (int) $product->scenic_spot_id,
            (int) $product->id,
            $levelId,
            $levelNo,
            $hotelId,
            $levelName,
        );
    }

    /**
     * @param  array<int, mixed>  $levelIds
     * @return array{hotel_level_id: int, room_level_id: int}|null
     */
    public function parseLevelIds(array $levelIds): ?array
    {
        $normalized = [];
        foreach ($levelIds as $levelId) {
            if ($levelId === null || $levelId === '') {
                continue;
            }
            $normalized[] = (int) $levelId;
        }

        if (count($normalized) < 2) {
            return null;
        }

        return [
            'hotel_level_id' => $normalized[0],
            'room_level_id' => $normalized[count($normalized) - 1],
        ];
    }

    private function findMap(int $productId, int $levelId): ?MeituanLevelMap
    {
        return MeituanLevelMap::query()
            ->where('product_id', $productId)
            ->where('level_id', $levelId)
            ->first();
    }

    private function upsertMap(
        int $scenicSpotId,
        int $productId,
        int $levelId,
        int $levelNo,
        ?int $hotelId,
        string $levelName,
    ): void {
        $existing = $this->findMap($productId, $levelId);
        if ($existing !== null) {
            if ($existing->level_no !== $levelNo || $existing->level_name !== $levelName
                || (int) $existing->hotel_id !== (int) $hotelId) {
                Log::info('美团层级映射更新', [
                    'product_id' => $productId,
                    'level_id' => $levelId,
                    'from_name' => $existing->level_name,
                    'to_name' => $levelName,
                    'from_hotel_id' => $existing->hotel_id,
                    'to_hotel_id' => $hotelId,
                ]);
            }

            $existing->fill([
                'scenic_spot_id' => $scenicSpotId,
                'level_no' => $levelNo,
                'hotel_id' => $hotelId,
                'level_name' => $levelName,
            ]);
            $existing->save();

            return;
        }

        MeituanLevelMap::query()->create([
            'scenic_spot_id' => $scenicSpotId,
            'product_id' => $productId,
            'level_id' => $levelId,
            'level_no' => $levelNo,
            'hotel_id' => $hotelId,
            'level_name' => $levelName,
        ]);
    }

    /**
     * @return list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>
     */
    public function candidatesForProductDate(Product $product, string $useDate): array
    {
        $prices = $product->prices()->where('date', $useDate)->with(['roomType.hotel'])->get();

        return $this->buildCandidates($product, $prices, $useDate);
    }

    /**
     * @param  Collection<int, Price>  $prices
     * @return list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>
     */
    private function buildCandidates(Product $product, Collection $prices, string $useDate): array
    {
        $candidates = [];
        foreach ($prices as $price) {
            $roomType = $price->roomType;
            $hotel = $roomType?->hotel;
            if ($hotel === null || $roomType === null) {
                continue;
            }

            $priceData = $this->productService->calculatePrice($product, (int) $roomType->id, $useDate);
            $candidates[] = [
                'hotel_id' => (int) $hotel->id,
                'hotel_name' => (string) $hotel->name,
                'room_type_id' => (int) $roomType->id,
                'room_type_name' => (string) $roomType->name,
                'sale_price' => round((float) ($priceData['sale_price'] ?? 0), 2),
            ];
        }

        return $candidates;
    }

    /**
     * @param  list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>  $candidates
     * @return list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>
     */
    private function filterByUnitPrice(array $candidates, float $unitPrice): array
    {
        $target = round($unitPrice, 2);

        return array_values(array_filter(
            $candidates,
            fn (array $row): bool => abs($row['sale_price'] - $target) <= self::UNIT_PRICE_MATCH_TOLERANCE,
        ));
    }

    /**
     * @param  Collection<int, Price>  $prices
     * @param  array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}  $match
     * @param  list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>  $candidates
     * @return array{
     *     price: Price,
     *     resolve_method: string,
     *     skip_inventory: bool,
     *     create_exception: bool,
     *     sku_confirmed: bool,
     *     candidates: list<array{hotel_id: int, hotel_name: string, room_type_id: int, room_type_name: string, sale_price: float}>
     * }
     */
    private function buildResult(
        Collection $prices,
        array $match,
        string $resolveMethod,
        bool $skipInventory,
        bool $createException,
        bool $skuConfirmed,
        array $candidates,
    ): array {
        $price = $prices->first(
            fn (Price $price): bool => (int) $price->room_type_id === $match['room_type_id']
        );
        if ($price === null) {
            $price = $prices->first();
        }

        return [
            'price' => $price,
            'resolve_method' => $resolveMethod,
            'skip_inventory' => $skipInventory,
            'create_exception' => $createException,
            'sku_confirmed' => $skuConfirmed,
            'candidates' => $candidates,
        ];
    }
}
