<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Http\Requests\OrderExportRequest;
use App\Models\Order;
use App\Models\ScenicSpot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class OrderExportService
{
    /**
     * @var list<string>
     */
    private const HEADERS = [
        '资源方',
        '景区',
        '系统服务商',
        '资源方订单号',
        '系统订单号',
        'OTA订单号',
        'OTA平台',
        '订单状态',
        '产品编码',
        '产品名称',
        '酒店编码',
        '酒店名称',
        '房型编码',
        '房型',
        '入住日期',
        '离店日期',
        '间夜数',
        '房间数',
        '入住人数',
        '联系人姓名',
        '联系电话',
        '联系邮箱',
        '入住人信息',
        '结算金额',
        '销售金额',
        '订单明细',
        '确认时间',
        '取消时间',
        '支付时间',
        '创建时间',
        '异常信息',
        '备注',
    ];

    public function export(OrderExportRequest $request): Response
    {
        $orders = $this->buildQuery($request)->get();

        $filename = 'orders_export_'.now()->format('Y-m-d_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $output = "\xEF\xBB\xBF";
        $output .= implode(',', self::HEADERS)."\n";

        $totalSettlement = 0.0;
        $totalAmount = 0.0;

        foreach ($orders as $order) {
            $row = $this->mapOrderToRow($order);
            $output .= $this->formatCsvRow($row)."\n";
            $totalSettlement += (float) ($order->settlement_amount ?? 0);
            $totalAmount += (float) ($order->total_amount ?? 0);
        }

        if ($orders->isNotEmpty()) {
            $output .= $this->formatCsvRow([
                '合计',
                '',
                '',
                '',
                '',
                '',
                '',
                (string) $orders->count().'笔',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                number_format($totalSettlement, 2, '.', ''),
                number_format($totalAmount, 2, '.', ''),
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ])."\n";
        }

        return response($output, 200, $headers);
    }

    private function buildQuery(OrderExportRequest $request): Builder
    {
        $query = Order::query()->with([
            'otaPlatform',
            'product.scenicSpot.resourceProvider',
            'product.scenicSpot.resourceProviders',
            'product.scenicSpot.softwareProvider',
            'hotel',
            'roomType',
            'items',
            'exceptionOrder',
        ]);

        $this->applyPermissionScope($query, $request->user());
        $this->applyDateOrFilter($query, $request);
        $this->applyListFilters($query, $request);

        return $query->orderBy('check_in_date', 'desc')->orderBy('id', 'desc');
    }

    private function applyPermissionScope(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $resourceProviderIds = $user->resourceProviders->pluck('id');
        $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($q) use ($resourceProviderIds): void {
            $q->whereIn('resource_providers.id', $resourceProviderIds);
        })->pluck('id');

        $query->whereHas('product', function ($q) use ($scenicSpotIds): void {
            $q->whereIn('scenic_spot_id', $scenicSpotIds);
        });
    }

    private function applyDateOrFilter(Builder $query, OrderExportRequest $request): void
    {
        $hasCheckIn = $request->filled('check_in_date_start') && $request->filled('check_in_date_end');
        $hasCreated = $request->filled('created_at_start') && $request->filled('created_at_end');

        $query->where(function (Builder $outer) use ($request, $hasCheckIn, $hasCreated): void {
            if ($hasCheckIn) {
                $outer->where(function (Builder $q) use ($request): void {
                    $q->where('check_in_date', '>=', $request->input('check_in_date_start'))
                        ->where('check_in_date', '<=', $request->input('check_in_date_end'));
                });
            }

            if ($hasCreated) {
                if ($hasCheckIn) {
                    $outer->orWhere(function (Builder $q) use ($request): void {
                        $q->whereDate('created_at', '>=', $request->input('created_at_start'))
                            ->whereDate('created_at', '<=', $request->input('created_at_end'));
                    });
                } else {
                    $outer->where(function (Builder $q) use ($request): void {
                        $q->whereDate('created_at', '>=', $request->input('created_at_start'))
                            ->whereDate('created_at', '<=', $request->input('created_at_end'));
                    });
                }
            }
        });
    }

    private function applyListFilters(Builder $query, OrderExportRequest $request): void
    {
        $statuses = $request->input('status');
        if (is_array($statuses) && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('ota_platform_id')) {
            $query->where('ota_platform_id', $request->integer('ota_platform_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('scenic_spot_id')) {
            $query->whereHas('product', function ($q) use ($request): void {
                $q->where('scenic_spot_id', $request->integer('scenic_spot_id'));
            });
        }

        if ($request->filled('order_no')) {
            $query->where('order_no', 'like', '%'.$request->string('order_no').'%');
        }

        if ($request->filled('ota_order_no')) {
            $query->where('ota_order_no', 'like', '%'.$request->string('ota_order_no').'%');
        }

        if ($request->filled('contact_name')) {
            $query->where('contact_name', 'like', '%'.$request->string('contact_name').'%');
        }

        $contactPhone = trim((string) $request->input('contact_phone', ''));
        if ($contactPhone !== '') {
            $query->where('contact_phone', $contactPhone);
        }
    }

    /**
     * @return list<string>
     */
    private function mapOrderToRow(Order $order): array
    {
        $scenicSpot = $order->product?->scenicSpot;
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::tryFrom((string) $order->status);

        return [
            $this->resourceProviderName($scenicSpot),
            $scenicSpot?->name ?? '',
            $scenicSpot?->softwareProvider?->name ?? '',
            $order->resource_order_no ?? '',
            $order->order_no ?? '',
            $order->ota_order_no ?? '',
            $order->otaPlatform?->name ?? '',
            $status?->label() ?? (string) $order->status,
            $order->product?->code ?? '',
            $order->product?->name ?? '',
            $order->hotel?->code ?? '',
            $order->hotel?->name ?? '',
            $order->roomType?->code ?? '',
            $order->roomType?->name ?? '',
            $order->check_in_date?->format('Y-m-d') ?? '',
            $order->check_out_date?->format('Y-m-d') ?? '',
            (string) $this->calculateNights($order),
            (string) ($order->room_count ?? 1),
            (string) ($order->guest_count ?? 1),
            $order->contact_name ?? '',
            $order->contact_phone ?? '',
            $order->contact_email ?? '',
            $this->formatGuests($order),
            $order->settlement_amount !== null ? number_format((float) $order->settlement_amount, 2, '.', '') : '',
            $order->total_amount !== null ? number_format((float) $order->total_amount, 2, '.', '') : '',
            $this->formatOrderItems($order->items),
            $this->formatDateTime($order->confirmed_at),
            $this->formatDateTime($order->cancelled_at),
            $this->formatDateTime($order->paid_at),
            $this->formatDateTime($order->created_at),
            $this->formatExceptions($order->exceptionOrder),
            $order->remark ?? '',
        ];
    }

    private function resourceProviderName(?\App\Models\ScenicSpot $scenicSpot): string
    {
        if ($scenicSpot === null) {
            return '';
        }

        if ($scenicSpot->relationLoaded('resourceProvider') && $scenicSpot->resourceProvider) {
            return $scenicSpot->resourceProvider->name;
        }

        if ($scenicSpot->relationLoaded('resourceProviders') && $scenicSpot->resourceProviders->isNotEmpty()) {
            return $scenicSpot->resourceProviders->pluck('name')->filter()->implode('、');
        }

        return '';
    }

    private function calculateNights(Order $order): int
    {
        if (! $order->check_in_date || ! $order->check_out_date) {
            return 0;
        }

        $nights = $order->check_in_date->diffInDays($order->check_out_date);

        return max(1, $nights);
    }

    private function formatGuests(Order $order): string
    {
        $guests = $order->guest_info;
        if (is_array($guests) && $guests !== []) {
            return collect($guests)->map(function (mixed $guest): string {
                $guest = (array) $guest;
                $name = (string) ($guest['name'] ?? $guest['Name'] ?? '');
                $idCode = (string) ($guest['idCode'] ?? $guest['cardNo'] ?? $guest['credentialNo'] ?? $guest['id_code'] ?? '');
                $type = $this->credentialTypeLabel((int) ($guest['credentialType'] ?? $guest['credential_type'] ?? 0));

                return trim("{$name}|{$type}|{$idCode}", '|');
            })->implode('；');
        }

        if ($order->card_no) {
            return (string) $order->card_no;
        }

        return '';
    }

    private function credentialTypeLabel(int $type): string
    {
        return match ($type) {
            0 => '身份证',
            1 => '护照',
            2 => '港澳通行证',
            3 => '台湾通行证',
            4 => '其他',
            default => $type > 0 ? "类型{$type}" : '',
        };
    }

    private function formatOrderItems(\Illuminate\Support\Collection $items): string
    {
        if ($items->isEmpty()) {
            return '';
        }

        return $items->map(function ($item): string {
            $date = $item->date instanceof Carbon
                ? $item->date->format('Y-m-d')
                : (string) $item->date;

            $unitPrice = $item->unit_price !== null
                ? number_format((float) $item->unit_price, 2, '.', '')
                : '';
            $totalPrice = $item->total_price !== null
                ? number_format((float) $item->total_price, 2, '.', '')
                : '';

            return sprintf('%s|%d|%s|%s', $date, (int) $item->quantity, $unitPrice, $totalPrice);
        })->implode('；');
    }

    private function formatExceptions(Collection $exceptions): string
    {
        if ($exceptions->isEmpty()) {
            return '';
        }

        return $exceptions->pluck('exception_message')->filter()->implode('；');
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<string>  $fields
     */
    private function formatCsvRow(array $fields): string
    {
        return implode(',', array_map(
            fn (string $field): string => $this->escapeCsvField($field),
            $fields
        ));
    }

    private function escapeCsvField(string $field): string
    {
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }
}
