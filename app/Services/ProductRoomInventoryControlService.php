<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductRoomInventoryControl;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductRoomInventoryControlService
{
    /**
     * @param array{
     *     hotel_id:int,
     *     room_type_ids:array<int,int>,
     *     start_date:string,
     *     end_date:string,
     *     note?:string|null
     * } $data
     */
    public function batchClose(Product $product, array $data, int $operatorId): int
    {
        return DB::transaction(function () use ($product, $data, $operatorId): int {
            $count = 0;
            $dates = $this->buildDateRange($data['start_date'], $data['end_date']);

            foreach ($data['room_type_ids'] as $roomTypeId) {
                foreach ($dates as $date) {
                    ProductRoomInventoryControl::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'hotel_id' => $data['hotel_id'],
                            'room_type_id' => (int) $roomTypeId,
                            'date' => $date,
                        ],
                        [
                            'is_closed' => true,
                            'note' => $data['note'] ?? null,
                            'created_by' => $operatorId,
                            'updated_by' => $operatorId,
                        ]
                    );
                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * @param array{
     *     hotel_id:int,
     *     room_type_ids:array<int,int>,
     *     start_date:string,
     *     end_date:string,
     *     note?:string|null
     * } $data
     */
    public function batchOpen(Product $product, array $data): int
    {
        return DB::transaction(function () use ($product, $data): int {
            $dates = $this->buildDateRange($data['start_date'], $data['end_date']);

            return ProductRoomInventoryControl::query()
                ->where('product_id', $product->id)
                ->where('hotel_id', $data['hotel_id'])
                ->whereIn('room_type_id', $data['room_type_ids'])
                ->whereIn('date', $dates)
                ->delete();
        });
    }

    public function paginateByProduct(Product $product, array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $query = ProductRoomInventoryControl::query()
            ->with(['hotel', 'roomType'])
            ->where('product_id', $product->id)
            ->orderBy('date')
            ->orderBy('hotel_id')
            ->orderBy('room_type_id');

        if (!empty($filters['hotel_id'])) {
            $query->where('hotel_id', (int) $filters['hotel_id']);
        }

        if (!empty($filters['room_type_id'])) {
            $query->where('room_type_id', (int) $filters['room_type_id']);
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
        }

        return $query->paginate($perPage);
    }

    /**
     * @return array<int, string>
     */
    private function buildDateRange(string $startDate, string $endDate): array
    {
        $period = CarbonPeriod::create($startDate, $endDate);

        return collect($period)
            ->map(static fn (Carbon $date): string => $date->format('Y-m-d'))
            ->values()
            ->all();
    }
}
