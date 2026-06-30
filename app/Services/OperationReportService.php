<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PkgOrderStatus;
use App\Http\Requests\OperationReportRequest;
use App\Models\OtaPlatform;
use App\Models\Order;
use App\Models\Pkg\PkgOrder;
use App\Models\ScenicSpot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationReportService
{
    public function buildReport(OperationReportRequest $request): array
    {
        $context = $this->resolveContext($request);
        $report = $this->buildReportForRange($request, $context);

        [$previousStartDate, $previousEndDate] = $this->resolvePreviousDateRange(
            $context['start_date'],
            $context['end_date'],
            $context['period'],
            $context['use_date_only']
        );

        $previousContext = array_merge($context, [
            'start_date' => $previousStartDate,
            'end_date' => $previousEndDate,
        ]);

        $previousStats = $this->buildStatsForRange($request, $previousContext);

        $report['comparison'] = $this->buildComparison(
            $report['stats'],
            $previousStats,
            $previousStartDate,
            $previousEndDate,
            $context['period']
        );

        return $report;
    }

    public function export(OperationReportRequest $request): Response
    {
        $report = $this->buildReport($request);
        $filename = 'operation_report_'.now()->format('Y-m-d_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response($this->generateCsv($report), 200, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveContext(OperationReportRequest $request): array
    {
        $period = $request->input('period', 'day');
        $dateType = $request->input('date_type', 'booking');
        $dateColumn = $dateType === 'arrival' ? 'check_in_date' : 'created_at';
        $selectedScenicSpotId = $request->input('scenic_spot_id');
        $selectedOtaPlatformId = $request->input('ota_platform_id');
        $useDateOnly = $dateType === 'arrival';

        $startDate = match ($period) {
            'realtime' => now()->startOfDay(),
            'day' => now()->subDay()->startOfDay(),
            'week' => now()->subWeek()->startOfDay(),
            'month' => now()->subMonth()->startOfDay(),
            'custom' => $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))->startOfDay()
                : now()->subDay()->startOfDay(),
            default => now()->subDay()->startOfDay(),
        };

        if ($period === 'custom' && $request->has('end_date')) {
            $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
        } elseif ($period === 'realtime') {
            $endDate = now();
        } elseif ($period === 'day') {
            $endDate = now()->subDay()->endOfDay();
        } elseif ($period === 'week') {
            $endDate = now()->subDay()->endOfDay();
        } elseif ($period === 'month') {
            $endDate = now()->subDay()->endOfDay();
        } else {
            $endDate = now();
        }

        $scenicSpotIds = $this->resolveScenicSpotIds($request, $selectedScenicSpotId);

        if (! empty($selectedScenicSpotId)) {
            $selectedScenicSpotId = (int) $selectedScenicSpotId;
        }

        return [
            'period' => $period,
            'date_type' => $dateType,
            'date_column' => $dateColumn,
            'use_date_only' => $useDateOnly,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'scenic_spot_ids' => $scenicSpotIds,
            'selected_scenic_spot_id' => ! empty($selectedScenicSpotId) ? (int) $selectedScenicSpotId : null,
            'selected_ota_platform_id' => ! empty($selectedOtaPlatformId) ? (int) $selectedOtaPlatformId : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildReportForRange(OperationReportRequest $request, array $context): array
    {
        [$orderQuery, $pkgOrderQuery] = $this->buildFilteredQueries($request, $context);

        $orderStats = $this->getOrderStats($orderQuery);
        $pkgOrderStats = $this->getPkgOrderStats($pkgOrderQuery);
        $totalStats = $this->mergeTotalStats($orderStats, $pkgOrderStats);

        $availableScenicSpotsQuery = ScenicSpot::query()->select('id', 'name');
        if ($context['scenic_spot_ids'] !== null) {
            $availableScenicSpotsQuery->whereIn('id', $context['scenic_spot_ids']);
        }
        $availableScenicSpots = $availableScenicSpotsQuery->orderBy('name')->get();

        return [
            'period' => $context['period'],
            'date_type' => $context['date_type'],
            'scenic_spot_id' => $context['selected_scenic_spot_id'],
            'ota_platform_id' => $context['selected_ota_platform_id'],
            'start_date' => $context['start_date']->format('Y-m-d H:i:s'),
            'end_date' => $context['end_date']->format('Y-m-d H:i:s'),
            'available_scenic_spots' => $availableScenicSpots,
            'available_channels' => $this->getAvailableChannels(),
            'stats' => $totalStats,
            'order_stats' => $orderStats,
            'pkg_order_stats' => $pkgOrderStats,
            'status_distribution' => $this->getStatusDistribution($orderQuery, $pkgOrderQuery),
            'metric_scopes' => $this->getMetricScopeDefinitions(),
            'platform_distribution' => $this->getPlatformDistribution(
                $context['start_date'],
                $context['scenic_spot_ids'],
                $context['end_date'],
                $context['date_column'],
                $context['use_date_only'],
                $context['selected_ota_platform_id']
            ),
            'time_trend' => $this->getTimeTrend($orderQuery, $pkgOrderQuery, $context['date_column']),
            'top_products' => $this->getTopProducts($orderQuery, $pkgOrderQuery),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int|float>
     */
    private function buildStatsForRange(OperationReportRequest $request, array $context): array
    {
        [$orderQuery, $pkgOrderQuery] = $this->buildFilteredQueries($request, $context);

        return $this->mergeTotalStats(
            $this->getOrderStats($orderQuery),
            $this->getPkgOrderStats($pkgOrderQuery)
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{0: Builder, 1: Builder}
     */
    private function buildFilteredQueries(OperationReportRequest $request, array $context): array
    {
        $dateColumn = $context['date_column'];
        $startDate = $context['start_date'];
        $endDate = $context['end_date'];
        $scenicSpotIds = $context['scenic_spot_ids'];
        $selectedOtaPlatformId = $context['selected_ota_platform_id'];

        $orderQuery = Order::query();
        $this->applyDateRangeFilter(
            $orderQuery,
            'orders.'.$dateColumn,
            $startDate,
            $endDate,
            $context['use_date_only']
        );
        if ($scenicSpotIds !== null) {
            $orderQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        if ($selectedOtaPlatformId !== null) {
            $orderQuery->where('ota_platform_id', $selectedOtaPlatformId);
        }

        $pkgOrderQuery = PkgOrder::query();
        $this->applyDateRangeFilter(
            $pkgOrderQuery,
            'pkg_orders.'.$dateColumn,
            $startDate,
            $endDate,
            $context['use_date_only']
        );
        if ($scenicSpotIds !== null) {
            $pkgOrderQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        if ($selectedOtaPlatformId !== null) {
            $pkgOrderQuery->where('ota_platform_id', $selectedOtaPlatformId);
        }

        return [$orderQuery, $pkgOrderQuery];
    }

    private function resolveScenicSpotIds(OperationReportRequest $request, mixed $selectedScenicSpotId): ?Collection
    {
        $scenicSpotIds = null;
        if (! $request->user()->isAdmin()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
        }

        if (! empty($selectedScenicSpotId)) {
            $selectedScenicSpotId = (int) $selectedScenicSpotId;
            if ($scenicSpotIds === null) {
                $scenicSpotIds = collect([$selectedScenicSpotId]);
            } else {
                $scenicSpotIds = $scenicSpotIds->intersect([$selectedScenicSpotId])->values();
            }
        }

        return $scenicSpotIds;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePreviousDateRange(
        Carbon $startDate,
        Carbon $endDate,
        string $period,
        bool $useDateOnly
    ): array {
        if ($period === 'realtime') {
            $elapsedSeconds = (int) $startDate->diffInSeconds($endDate, absolute: true);
            $previousStartDate = $startDate->copy()->subDay();
            $previousEndDate = $previousStartDate->copy()->addSeconds($elapsedSeconds);

            return [$previousStartDate, $previousEndDate];
        }

        if ($useDateOnly) {
            $periodDays = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay(), absolute: true) + 1;
            $previousEndDate = $startDate->copy()->subDay()->endOfDay();
            $previousStartDate = $previousEndDate->copy()->subDays($periodDays - 1)->startOfDay();

            return [$previousStartDate, $previousEndDate];
        }

        $durationSeconds = (int) $startDate->diffInSeconds($endDate, absolute: true);
        $previousEndDate = $startDate->copy()->subSecond();
        $previousStartDate = $previousEndDate->copy()->subSeconds($durationSeconds);

        return [$previousStartDate, $previousEndDate];
    }

    /**
     * @param  array<string, int|float>  $current
     * @param  array<string, int|float>  $previous
     * @return array<string, mixed>
     */
    private function buildComparison(
        array $current,
        array $previous,
        Carbon $previousStartDate,
        Carbon $previousEndDate,
        string $period
    ): array {
        $changes = [];

        foreach ($current as $key => $value) {
            $currentValue = (float) $value;
            $previousValue = (float) ($previous[$key] ?? 0);

            $changes[$key] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'change_value' => $currentValue - $previousValue,
                'change_percent' => $this->calculateChangePercent($currentValue, $previousValue),
            ];
        }

        return [
            'label' => $this->getComparisonLabel($period),
            'previous_start_date' => $previousStartDate->format('Y-m-d H:i:s'),
            'previous_end_date' => $previousEndDate->format('Y-m-d H:i:s'),
            'stats' => $previous,
            'changes' => $changes,
        ];
    }

    private function calculateChangePercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? null : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function getComparisonLabel(string $period): string
    {
        return match ($period) {
            'realtime' => '昨日同时段',
            'day' => '前日',
            'week', 'month', 'custom' => '上一周期',
            default => '上一周期',
        };
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function generateCsv(array $report): string
    {
        $output = "\xEF\xBB\xBF";
        $output .= $this->formatCsvRow(['运营快报导出'])."\n";
        $output .= $this->formatCsvRow(['统计区间', $report['start_date'].' ~ '.$report['end_date']])."\n";
        $output .= $this->formatCsvRow(['对比区间', ($report['comparison']['previous_start_date'] ?? '').' ~ '.($report['comparison']['previous_end_date'] ?? '')])."\n";
        $output .= "\n";

        $output .= $this->formatCsvRow(['核心指标', '当前值', '对比值', '环比(%)'])."\n";
        $summaryRows = [
            ['下单总量', 'total_orders'],
            ['下单金额', 'total_amount'],
            ['有效订单数', 'valid_orders'],
            ['有效销售额', 'valid_amount'],
            ['有效结算额', 'valid_settlement_amount'],
            ['预订成功订单', 'successful_orders'],
            ['已核销订单', 'verified_orders'],
            ['核销金额', 'verified_total_amount'],
            ['已取消订单', 'cancelled_orders'],
            ['取消金额', 'cancelled_amount'],
        ];

        foreach ($summaryRows as [$label, $key]) {
            $change = $report['comparison']['changes'][$key] ?? null;
            $output .= $this->formatCsvRow([
                $label,
                $this->formatExportValue($report['stats'][$key] ?? 0),
                $this->formatExportValue($change['previous'] ?? 0),
                $this->formatExportChangePercent($change['change_percent'] ?? null),
            ])."\n";
        }

        $output .= "\n".$this->formatCsvRow(['OTA平台分布'])."\n";
        $output .= $this->formatCsvRow(['平台', '下单总量', '有效订单数', '核销订单数', '下单金额', '有效销售额', '核销金额', '占比(%)'])."\n";
        foreach ($report['platform_distribution'] as $platform) {
            $output .= $this->formatCsvRow([
                (string) $platform['name'],
                (string) $platform['count'],
                (string) $platform['valid_count'],
                (string) $platform['verified_count'],
                $this->formatExportValue($platform['total_amount']),
                $this->formatExportValue($platform['valid_amount']),
                $this->formatExportValue($platform['verified_amount']),
                $this->formatExportValue($platform['percentage']),
            ])."\n";
        }

        $output .= "\n".$this->formatCsvRow(['时间趋势'])."\n";
        $output .= $this->formatCsvRow(['日期', '下单总量', '有效订单数', '核销订单数', '下单金额', '有效销售额', '核销金额'])."\n";
        foreach ($report['time_trend'] as $trend) {
            $output .= $this->formatCsvRow([
                (string) $trend['date'],
                (string) $trend['count'],
                (string) $trend['valid_count'],
                (string) $trend['verified_count'],
                $this->formatExportValue($trend['total_amount']),
                $this->formatExportValue($trend['valid_amount']),
                $this->formatExportValue($trend['verified_amount']),
            ])."\n";
        }

        $output .= "\n".$this->formatCsvRow(['销售TOP10产品'])."\n";
        $output .= $this->formatCsvRow(['产品名称', '产品类型', '下单总量', '有效订单数', '核销订单数', '下单金额', '有效销售额', '核销金额'])."\n";
        foreach ($report['top_products'] as $product) {
            $output .= $this->formatCsvRow([
                (string) $product['product_name'],
                $product['product_type'] === 'order' ? '普通产品' : '打包产品',
                (string) $product['order_count'],
                (string) $product['valid_order_count'],
                (string) $product['verified_order_count'],
                $this->formatExportValue($product['total_amount']),
                $this->formatExportValue($product['valid_amount']),
                $this->formatExportValue($product['verified_amount']),
            ])."\n";
        }

        $output .= "\n".$this->formatCsvRow(['状态分布（归并）'])."\n";
        $output .= $this->formatCsvRow(['状态', '订单数', '占比(%)', '金额'])."\n";
        foreach ($report['status_distribution']['unified'] ?? [] as $status) {
            $output .= $this->formatCsvRow([
                (string) $status['label'],
                (string) $status['count'],
                $this->formatExportValue($status['percentage']),
                $this->formatExportValue($status['total_amount']),
            ])."\n";
        }

        return $output;
    }

    private function formatExportChangePercent(?float $changePercent): string
    {
        if ($changePercent === null) {
            return '新增';
        }

        return $this->formatExportValue($changePercent);
    }

    private function formatExportValue(float|int|string $value): string
    {
        if (is_numeric($value) && str_contains((string) $value, '.')) {
            return number_format((float) $value, 2, '.', '');
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $fields
     */
    private function formatCsvRow(array $fields): string
    {
        return implode(',', array_map(
            fn (string $field): string => $this->escapeCsvField($field),
            array_map('strval', $fields)
        ));
    }

    private function escapeCsvField(string $field): string
    {
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }

    /**
     * @param  array<string, int|float>  $orderStats
     * @param  array<string, int|float>  $pkgOrderStats
     * @return array<string, int|float>
     */
    private function mergeTotalStats(array $orderStats, array $pkgOrderStats): array
    {
        return [
            'total_orders' => $orderStats['total_orders'] + $pkgOrderStats['total_orders'],
            'total_amount' => (float) $orderStats['total_amount'] + (float) $pkgOrderStats['total_amount'],
            'total_settlement_amount' => (float) $orderStats['total_settlement_amount'] + (float) $pkgOrderStats['total_settlement_amount'],
            'valid_orders' => $orderStats['valid_orders'] + $pkgOrderStats['valid_orders'],
            'valid_amount' => (float) $orderStats['valid_amount'] + (float) $pkgOrderStats['valid_amount'],
            'valid_settlement_amount' => (float) $orderStats['valid_settlement_amount'] + (float) $pkgOrderStats['valid_settlement_amount'],
            'successful_orders' => $orderStats['successful_orders'] + $pkgOrderStats['successful_orders'],
            'verified_total_amount' => (float) $orderStats['verified_total_amount'] + (float) $pkgOrderStats['verified_total_amount'],
            'confirmed_orders' => $orderStats['confirmed_orders'] + $pkgOrderStats['confirmed_orders'],
            'verified_orders' => $orderStats['verified_orders'] + $pkgOrderStats['verified_orders'],
            'cancelled_orders' => $orderStats['cancelled_orders'] + $pkgOrderStats['cancelled_orders'],
            'cancelled_amount' => (float) $orderStats['cancelled_amount'] + (float) $pkgOrderStats['cancelled_amount'],
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function getOrderStats(Builder $query): array
    {
        $invalidStatuses = $this->orderInvalidStatuses();
        $successfulStatuses = $this->orderSuccessfulStatuses();

        return [
            'total_orders' => (clone $query)->count(),
            'total_amount' => (float) ((clone $query)->sum('total_amount') ?? 0),
            'total_settlement_amount' => (float) ((clone $query)->sum('settlement_amount') ?? 0),
            'valid_orders' => (clone $query)->whereNotIn('status', $invalidStatuses)->count(),
            'valid_amount' => (float) ((clone $query)->whereNotIn('status', $invalidStatuses)->sum('total_amount') ?? 0),
            'valid_settlement_amount' => (float) ((clone $query)->whereNotIn('status', $invalidStatuses)->sum('settlement_amount') ?? 0),
            'successful_orders' => (clone $query)->whereIn('status', $successfulStatuses)->count(),
            'verified_total_amount' => (float) ((clone $query)
                ->where('status', OrderStatus::VERIFIED->value)
                ->sum('total_amount') ?? 0),
            'confirmed_orders' => (clone $query)->where('status', OrderStatus::CONFIRMED->value)->count(),
            'verified_orders' => (clone $query)->where('status', OrderStatus::VERIFIED->value)->count(),
            'cancelled_orders' => (clone $query)->where('status', OrderStatus::CANCEL_APPROVED->value)->count(),
            'cancelled_amount' => (float) ((clone $query)
                ->where('status', OrderStatus::CANCEL_APPROVED->value)
                ->sum('total_amount') ?? 0),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function getPkgOrderStats(Builder $query): array
    {
        $invalidStatuses = $this->pkgOrderInvalidStatuses();
        $successfulStatuses = $this->pkgOrderSuccessfulStatuses();

        return [
            'total_orders' => (clone $query)->count(),
            'total_amount' => (float) ((clone $query)->sum('total_amount') ?? 0),
            'total_settlement_amount' => (float) ((clone $query)->sum('settlement_amount') ?? 0),
            'valid_orders' => (clone $query)->whereNotIn('status', $invalidStatuses)->count(),
            'valid_amount' => (float) ((clone $query)->whereNotIn('status', $invalidStatuses)->sum('total_amount') ?? 0),
            'valid_settlement_amount' => (float) ((clone $query)->whereNotIn('status', $invalidStatuses)->sum('settlement_amount') ?? 0),
            'successful_orders' => (clone $query)->whereIn('status', $successfulStatuses)->count(),
            'verified_total_amount' => (float) ((clone $query)
                ->where('status', PkgOrderStatus::VERIFIED->value)
                ->sum('total_amount') ?? 0),
            'confirmed_orders' => (clone $query)->where('status', PkgOrderStatus::CONFIRMED->value)->count(),
            'verified_orders' => (clone $query)->where('status', PkgOrderStatus::VERIFIED->value)->count(),
            'cancelled_orders' => (clone $query)->where('status', PkgOrderStatus::CANCELLED->value)->count(),
            'cancelled_amount' => (float) ((clone $query)
                ->where('status', PkgOrderStatus::CANCELLED->value)
                ->sum('total_amount') ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function orderInvalidStatuses(): array
    {
        return [
            OrderStatus::CANCEL_APPROVED->value,
            OrderStatus::REJECTED->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function orderSuccessfulStatuses(): array
    {
        return [
            OrderStatus::CONFIRMED->value,
            OrderStatus::VERIFIED->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function pkgOrderInvalidStatuses(): array
    {
        return [
            PkgOrderStatus::CANCELLED->value,
            PkgOrderStatus::FAILED->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function pkgOrderSuccessfulStatuses(): array
    {
        return [
            PkgOrderStatus::CONFIRMED->value,
            PkgOrderStatus::VERIFIED->value,
        ];
    }

    private function getAvailableChannels()
    {
        return OtaPlatform::query()
            ->where('is_active', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    private function getStatusDistribution(Builder $orderQuery, Builder $pkgOrderQuery): array
    {
        $orderStatuses = $this->mapOrderStatusRows(
            (clone $orderQuery)
                ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as total_amount'))
                ->groupBy('status')
                ->get()
        );

        $pkgOrderStatuses = $this->mapPkgOrderStatusRows(
            (clone $pkgOrderQuery)
                ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as total_amount'))
                ->groupBy('status')
                ->get()
        );

        return [
            'orders' => $this->appendStatusPercentages($orderStatuses),
            'pkg_orders' => $this->appendStatusPercentages($pkgOrderStatuses),
            'unified' => $this->buildUnifiedStatusDistribution($orderStatuses, $pkgOrderStatuses),
        ];
    }

    private function mapOrderStatusRows($rows)
    {
        return $rows->map(function ($item) {
            if ($item->status instanceof OrderStatus) {
                $statusValue = $item->status->value;
                $statusEnum = $item->status;
            } else {
                $statusValue = (string) $item->status;
                $statusEnum = OrderStatus::from($statusValue);
            }

            return [
                'status' => $statusValue,
                'label' => $statusEnum->label(),
                'count' => (int) $item->count,
                'total_amount' => (float) $item->total_amount ?? 0,
                'type' => 'order',
            ];
        })->values();
    }

    private function mapPkgOrderStatusRows($rows)
    {
        return $rows->map(function ($item) {
            if ($item->status instanceof PkgOrderStatus) {
                $statusValue = $item->status->value;
                $statusEnum = $item->status;
            } else {
                $statusValue = (string) $item->status;
                $statusEnum = PkgOrderStatus::from($statusValue);
            }

            return [
                'status' => $statusValue,
                'label' => $statusEnum->label(),
                'count' => (int) $item->count,
                'total_amount' => (float) $item->total_amount ?? 0,
                'type' => 'pkg_order',
            ];
        })->values();
    }

    private function appendStatusPercentages($statuses)
    {
        $totalCount = $statuses->sum('count');

        return $statuses->map(function (array $item) use ($totalCount) {
            $item['percentage'] = $totalCount > 0 ? round(($item['count'] / $totalCount) * 100, 2) : 0;

            return $item;
        })->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $orderStatuses
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $pkgOrderStatuses
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildUnifiedStatusDistribution($orderStatuses, $pkgOrderStatuses)
    {
        $groups = [];

        foreach ($orderStatuses as $item) {
            $key = $this->mapOrderStatusToUnified($item['status']);
            $this->accumulateUnifiedStatusGroup($groups, $key, $item['count'], $item['total_amount']);
        }

        foreach ($pkgOrderStatuses as $item) {
            $key = $this->mapPkgStatusToUnified($item['status']);
            $this->accumulateUnifiedStatusGroup($groups, $key, $item['count'], $item['total_amount']);
        }

        $totalCount = array_sum(array_column($groups, 'count'));

        return collect($groups)
            ->map(function (array $item) use ($totalCount) {
                $item['percentage'] = $totalCount > 0 ? round(($item['count'] / $totalCount) * 100, 2) : 0;

                return $item;
            })
            ->sortByDesc('count')
            ->values();
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     */
    private function accumulateUnifiedStatusGroup(array &$groups, string $key, int $count, float $amount): void
    {
        if (! isset($groups[$key])) {
            $groups[$key] = [
                'status' => $key,
                'label' => $this->unifiedStatusLabel($key),
                'count' => 0,
                'total_amount' => 0.0,
                'type' => 'unified',
            ];
        }

        $groups[$key]['count'] += $count;
        $groups[$key]['total_amount'] += $amount;
    }

    private function mapOrderStatusToUnified(string $status): string
    {
        return match ($status) {
            OrderStatus::PAID_PENDING->value,
            OrderStatus::CONFIRMING->value,
            OrderStatus::CANCEL_REQUESTED->value => 'pending',
            OrderStatus::CONFIRMED->value => 'confirmed',
            OrderStatus::VERIFIED->value => 'verified',
            OrderStatus::CANCEL_APPROVED->value => 'cancelled',
            OrderStatus::REJECTED->value => 'failed',
            OrderStatus::CANCEL_REJECTED->value => 'cancel_rejected',
            default => 'other',
        };
    }

    private function mapPkgStatusToUnified(string $status): string
    {
        return match ($status) {
            PkgOrderStatus::PAID->value => 'pending',
            PkgOrderStatus::CONFIRMED->value => 'confirmed',
            PkgOrderStatus::VERIFIED->value => 'verified',
            PkgOrderStatus::CANCELLED->value => 'cancelled',
            PkgOrderStatus::FAILED->value => 'failed',
            default => 'other',
        };
    }

    private function unifiedStatusLabel(string $key): string
    {
        return match ($key) {
            'pending' => '待处理',
            'confirmed' => '预订成功',
            'verified' => '已核销',
            'cancelled' => '已取消',
            'failed' => '预订失败',
            'cancel_rejected' => '取消被拒',
            default => '其他',
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private function getMetricScopeDefinitions(): array
    {
        return [
            [
                'key' => 'valid',
                'label' => '有效销售',
                'description' => '排除已取消、拒单、失败订单，反映真实销售规模。',
            ],
            [
                'key' => 'gross',
                'label' => '下单总量',
                'description' => '统计周期内全部下单记录，包含后续取消的订单。',
            ],
            [
                'key' => 'fulfilled',
                'label' => '核销履约',
                'description' => '仅统计已核销订单，反映实际到场/履约金额。',
            ],
        ];
    }

    private function getPlatformDistribution(
        $startDate,
        $scenicSpotIds,
        $endDate = null,
        string $dateColumn = 'created_at',
        bool $useDateOnly = false,
        ?int $otaPlatformId = null
    ) {
        $endDate = $endDate ?? now();
        $orderInvalid = $this->sqlInList($this->orderInvalidStatuses());
        $pkgInvalid = $this->sqlInList($this->pkgOrderInvalidStatuses());
        $orderVerified = $this->sqlInList([OrderStatus::VERIFIED->value]);
        $pkgVerified = $this->sqlInList([PkgOrderStatus::VERIFIED->value]);

        $orderPlatformsQuery = Order::query();
        $this->applyDateRangeFilter($orderPlatformsQuery, 'orders.'.$dateColumn, $startDate, $endDate, $useDateOnly);

        if ($scenicSpotIds !== null) {
            $orderPlatformsQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        if ($otaPlatformId !== null) {
            $orderPlatformsQuery->where('orders.ota_platform_id', $otaPlatformId);
        }

        $orderPlatforms = $orderPlatformsQuery
            ->join('ota_platforms', 'orders.ota_platform_id', '=', 'ota_platforms.id')
            ->select(
                'ota_platforms.id',
                'ota_platforms.name',
                DB::raw('count(*) as count'),
                DB::raw('sum(orders.total_amount) as total_amount'),
                DB::raw('sum(orders.settlement_amount) as settlement_amount'),
                DB::raw("sum(case when orders.status not in ({$orderInvalid}) then orders.total_amount else 0 end) as valid_amount"),
                DB::raw("sum(case when orders.status not in ({$orderInvalid}) then orders.settlement_amount else 0 end) as valid_settlement_amount"),
                DB::raw("sum(case when orders.status not in ({$orderInvalid}) then 1 else 0 end) as valid_count"),
                DB::raw("sum(case when orders.status in ({$orderVerified}) then orders.total_amount else 0 end) as verified_amount"),
                DB::raw("sum(case when orders.status in ({$orderVerified}) then 1 else 0 end) as verified_count")
            )
            ->groupBy('ota_platforms.id', 'ota_platforms.name')
            ->get();

        $pkgOrderPlatformsQuery = PkgOrder::query();
        $this->applyDateRangeFilter($pkgOrderPlatformsQuery, 'pkg_orders.'.$dateColumn, $startDate, $endDate, $useDateOnly);

        if ($scenicSpotIds !== null) {
            $pkgOrderPlatformsQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        if ($otaPlatformId !== null) {
            $pkgOrderPlatformsQuery->where('pkg_orders.ota_platform_id', $otaPlatformId);
        }

        $pkgOrderPlatforms = $pkgOrderPlatformsQuery
            ->join('ota_platforms', 'pkg_orders.ota_platform_id', '=', 'ota_platforms.id')
            ->select(
                'ota_platforms.id',
                'ota_platforms.name',
                DB::raw('count(*) as count'),
                DB::raw('sum(pkg_orders.total_amount) as total_amount'),
                DB::raw('sum(pkg_orders.settlement_amount) as settlement_amount'),
                DB::raw("sum(case when pkg_orders.status not in ({$pkgInvalid}) then pkg_orders.total_amount else 0 end) as valid_amount"),
                DB::raw("sum(case when pkg_orders.status not in ({$pkgInvalid}) then pkg_orders.settlement_amount else 0 end) as valid_settlement_amount"),
                DB::raw("sum(case when pkg_orders.status not in ({$pkgInvalid}) then 1 else 0 end) as valid_count"),
                DB::raw("sum(case when pkg_orders.status in ({$pkgVerified}) then pkg_orders.total_amount else 0 end) as verified_amount"),
                DB::raw("sum(case when pkg_orders.status in ({$pkgVerified}) then 1 else 0 end) as verified_count")
            )
            ->groupBy('ota_platforms.id', 'ota_platforms.name')
            ->get();

        $platformMap = [];

        foreach ($orderPlatforms as $platform) {
            $id = $platform->id;
            if (! isset($platformMap[$id])) {
                $platformMap[$id] = $this->emptyPlatformRow($id, $platform->name);
            }
            $platformMap[$id]['count'] += $platform->count;
            $platformMap[$id]['valid_count'] += (int) $platform->valid_count;
            $platformMap[$id]['total_amount'] += (float) $platform->total_amount;
            $platformMap[$id]['settlement_amount'] += (float) $platform->settlement_amount;
            $platformMap[$id]['valid_amount'] += (float) $platform->valid_amount;
            $platformMap[$id]['valid_settlement_amount'] += (float) $platform->valid_settlement_amount;
            $platformMap[$id]['verified_count'] += (int) $platform->verified_count;
            $platformMap[$id]['verified_amount'] += (float) $platform->verified_amount;
        }

        foreach ($pkgOrderPlatforms as $platform) {
            $id = $platform->id;
            if (! isset($platformMap[$id])) {
                $platformMap[$id] = $this->emptyPlatformRow($id, $platform->name);
            }
            $platformMap[$id]['count'] += $platform->count;
            $platformMap[$id]['valid_count'] += (int) $platform->valid_count;
            $platformMap[$id]['total_amount'] += (float) $platform->total_amount;
            $platformMap[$id]['settlement_amount'] += (float) $platform->settlement_amount;
            $platformMap[$id]['valid_amount'] += (float) $platform->valid_amount;
            $platformMap[$id]['valid_settlement_amount'] += (float) $platform->valid_settlement_amount;
            $platformMap[$id]['verified_count'] += (int) $platform->verified_count;
            $platformMap[$id]['verified_amount'] += (float) $platform->verified_amount;
        }

        $totalCount = array_sum(array_column($platformMap, 'count'));
        $platforms = array_values($platformMap);
        foreach ($platforms as &$platform) {
            $platform['percentage'] = $totalCount > 0 ? round(($platform['count'] / $totalCount) * 100, 2) : 0;
        }

        return $platforms;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function emptyPlatformRow(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'count' => 0,
            'valid_count' => 0,
            'total_amount' => 0,
            'settlement_amount' => 0,
            'valid_amount' => 0,
            'valid_settlement_amount' => 0,
            'verified_count' => 0,
            'verified_amount' => 0,
        ];
    }

    private function getTimeTrend(Builder $orderQuery, Builder $pkgOrderQuery, string $dateColumn = 'created_at')
    {
        $orderInvalid = $this->sqlInList($this->orderInvalidStatuses());
        $pkgInvalid = $this->sqlInList($this->pkgOrderInvalidStatuses());
        $orderVerified = $this->sqlInList([OrderStatus::VERIFIED->value]);
        $pkgVerified = $this->sqlInList([PkgOrderStatus::VERIFIED->value]);

        $orderTrend = (clone $orderQuery)
            ->select(
                DB::raw("DATE({$dateColumn}) as date"),
                DB::raw('count(*) as count'),
                DB::raw('sum(total_amount) as total_amount'),
                DB::raw("sum(case when status not in ({$orderInvalid}) then 1 else 0 end) as valid_count"),
                DB::raw("sum(case when status not in ({$orderInvalid}) then total_amount else 0 end) as valid_amount"),
                DB::raw("sum(case when status in ({$orderVerified}) then 1 else 0 end) as verified_count"),
                DB::raw("sum(case when status in ({$orderVerified}) then total_amount else 0 end) as verified_amount")
            )
            ->groupBy(DB::raw("DATE({$dateColumn})"))
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => $item->count,
                    'valid_count' => (int) $item->valid_count,
                    'total_amount' => (float) $item->total_amount ?? 0,
                    'valid_amount' => (float) $item->valid_amount ?? 0,
                    'verified_count' => (int) $item->verified_count,
                    'verified_amount' => (float) $item->verified_amount ?? 0,
                ];
            });

        $pkgOrderTrend = (clone $pkgOrderQuery)
            ->select(
                DB::raw("DATE({$dateColumn}) as date"),
                DB::raw('count(*) as count'),
                DB::raw('sum(total_amount) as total_amount'),
                DB::raw("sum(case when status not in ({$pkgInvalid}) then 1 else 0 end) as valid_count"),
                DB::raw("sum(case when status not in ({$pkgInvalid}) then total_amount else 0 end) as valid_amount"),
                DB::raw("sum(case when status in ({$pkgVerified}) then 1 else 0 end) as verified_count"),
                DB::raw("sum(case when status in ({$pkgVerified}) then total_amount else 0 end) as verified_amount")
            )
            ->groupBy(DB::raw("DATE({$dateColumn})"))
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => $item->count,
                    'valid_count' => (int) $item->valid_count,
                    'total_amount' => (float) $item->total_amount ?? 0,
                    'valid_amount' => (float) $item->valid_amount ?? 0,
                    'verified_count' => (int) $item->verified_count,
                    'verified_amount' => (float) $item->verified_amount ?? 0,
                ];
            });

        $allDates = array_unique(array_merge($orderTrend->keys()->toArray(), $pkgOrderTrend->keys()->toArray()));
        sort($allDates);

        $trend = [];
        foreach ($allDates as $date) {
            $orderData = $orderTrend->get($date, [
                'count' => 0,
                'valid_count' => 0,
                'total_amount' => 0,
                'valid_amount' => 0,
                'verified_count' => 0,
                'verified_amount' => 0,
            ]);
            $pkgOrderData = $pkgOrderTrend->get($date, [
                'count' => 0,
                'valid_count' => 0,
                'total_amount' => 0,
                'valid_amount' => 0,
                'verified_count' => 0,
                'verified_amount' => 0,
            ]);

            $trend[] = [
                'date' => $date,
                'count' => $orderData['count'] + $pkgOrderData['count'],
                'valid_count' => $orderData['valid_count'] + $pkgOrderData['valid_count'],
                'verified_count' => $orderData['verified_count'] + $pkgOrderData['verified_count'],
                'total_amount' => (float) $orderData['total_amount'] + (float) $pkgOrderData['total_amount'],
                'valid_amount' => (float) $orderData['valid_amount'] + (float) $pkgOrderData['valid_amount'],
                'verified_amount' => (float) $orderData['verified_amount'] + (float) $pkgOrderData['verified_amount'],
            ];
        }

        return $trend;
    }

    private function getTopProducts(Builder $orderQuery, Builder $pkgOrderQuery)
    {
        $orderInvalid = $this->sqlInList($this->orderInvalidStatuses());
        $pkgInvalid = $this->sqlInList($this->pkgOrderInvalidStatuses());
        $orderVerified = $this->sqlInList([OrderStatus::VERIFIED->value]);
        $pkgVerified = $this->sqlInList([PkgOrderStatus::VERIFIED->value]);

        $orderProducts = (clone $orderQuery)
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->select(
                DB::raw("'order' as product_type"),
                'products.id as product_id',
                'products.name as product_name',
                DB::raw('count(*) as order_count'),
                DB::raw('sum(orders.total_amount) as total_amount'),
                DB::raw('sum(orders.settlement_amount) as settlement_amount'),
                DB::raw("sum(case when orders.status not in ({$orderInvalid}) then orders.total_amount else 0 end) as valid_amount"),
                DB::raw("sum(case when orders.status not in ({$orderInvalid}) then orders.settlement_amount else 0 end) as valid_settlement_amount"),
                DB::raw("sum(case when orders.status not in ({$orderInvalid}) then 1 else 0 end) as valid_order_count"),
                DB::raw("sum(case when orders.status in ({$orderVerified}) then orders.total_amount else 0 end) as verified_amount"),
                DB::raw("sum(case when orders.status in ({$orderVerified}) then 1 else 0 end) as verified_order_count")
            )
            ->groupBy('products.id', 'products.name')
            ->get()
            ->map(function ($item) {
                return [
                    'product_type' => $item->product_type,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'order_count' => (int) $item->order_count,
                    'valid_order_count' => (int) $item->valid_order_count,
                    'total_amount' => (float) $item->total_amount,
                    'settlement_amount' => (float) $item->settlement_amount,
                    'valid_amount' => (float) $item->valid_amount,
                    'valid_settlement_amount' => (float) $item->valid_settlement_amount,
                    'verified_order_count' => (int) $item->verified_order_count,
                    'verified_amount' => (float) $item->verified_amount,
                ];
            });

        $pkgOrderProducts = (clone $pkgOrderQuery)
            ->join('pkg_products', 'pkg_orders.pkg_product_id', '=', 'pkg_products.id')
            ->select(
                DB::raw("'pkg_order' as product_type"),
                'pkg_products.id as product_id',
                'pkg_products.product_name as product_name',
                DB::raw('count(*) as order_count'),
                DB::raw('sum(pkg_orders.total_amount) as total_amount'),
                DB::raw('sum(pkg_orders.settlement_amount) as settlement_amount'),
                DB::raw("sum(case when pkg_orders.status not in ({$pkgInvalid}) then pkg_orders.total_amount else 0 end) as valid_amount"),
                DB::raw("sum(case when pkg_orders.status not in ({$pkgInvalid}) then pkg_orders.settlement_amount else 0 end) as valid_settlement_amount"),
                DB::raw("sum(case when pkg_orders.status not in ({$pkgInvalid}) then 1 else 0 end) as valid_order_count"),
                DB::raw("sum(case when pkg_orders.status in ({$pkgVerified}) then pkg_orders.total_amount else 0 end) as verified_amount"),
                DB::raw("sum(case when pkg_orders.status in ({$pkgVerified}) then 1 else 0 end) as verified_order_count")
            )
            ->groupBy('pkg_products.id', 'pkg_products.product_name')
            ->get()
            ->map(function ($item) {
                return [
                    'product_type' => $item->product_type,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'order_count' => (int) $item->order_count,
                    'valid_order_count' => (int) $item->valid_order_count,
                    'total_amount' => (float) $item->total_amount,
                    'settlement_amount' => (float) $item->settlement_amount,
                    'valid_amount' => (float) $item->valid_amount,
                    'valid_settlement_amount' => (float) $item->valid_settlement_amount,
                    'verified_order_count' => (int) $item->verified_order_count,
                    'verified_amount' => (float) $item->verified_amount,
                ];
            });

        return $orderProducts
            ->concat($pkgOrderProducts)
            ->sortByDesc('valid_amount')
            ->take(10)
            ->values();
    }

    /**
     * @param  list<string>  $values
     */
    private function sqlInList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value): string => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');
    }

    private function applyDateRangeFilter($query, string $column, $startDate, $endDate, bool $useDateOnly = false): void
    {
        if ($useDateOnly) {
            $query->whereDate($column, '>=', $startDate->toDateString())
                ->whereDate($column, '<=', $endDate->toDateString());

            return;
        }

        $query->where($column, '>=', $startDate)
            ->where($column, '<=', $endDate);
    }
}
