<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PkgOrderStatus;
use App\Http\Requests\OperationReportRequest;
use App\Models\OtaPlatform;
use App\Models\Order;
use App\Models\Pkg\PkgOrder;
use App\Models\ScenicSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OperationReportController extends Controller
{
    /**
     * 运营快报统计数据
     * 
     * @param OperationReportRequest $request
     * @return JsonResponse
     */
    public function index(OperationReportRequest $request): JsonResponse
    {
        $period = $request->input('period', 'day'); // realtime, day, week, month, custom
        $dateType = $request->input('date_type', 'booking'); // booking(预定日期), arrival(预达日期)
        $dateColumn = $dateType === 'arrival' ? 'check_in_date' : 'created_at';
        $selectedScenicSpotId = $request->input('scenic_spot_id');
        $selectedOtaPlatformId = $request->input('ota_platform_id');

        // 计算时间范围
        $startDate = match($period) {
            'realtime' => now()->startOfDay(), // 今天 00:00:00
            'day' => now()->subDay()->startOfDay(), // 昨天 00:00:00
            'week' => now()->subWeek()->startOfDay(), // 7天前 00:00:00
            'month' => now()->subMonth()->startOfDay(), // 1个月前 00:00:00
            'custom' => $request->input('start_date') 
                ? \Carbon\Carbon::parse($request->input('start_date'))->startOfDay()
                : now()->subDay()->startOfDay(),
            default => now()->subDay()->startOfDay(),
        };
        
        // 自定义日期范围的结束时间
        $endDate = null;
        if ($period === 'custom' && $request->has('end_date')) {
            $endDate = \Carbon\Carbon::parse($request->input('end_date'))->endOfDay();
        } elseif ($period === 'realtime') {
            $endDate = now(); // 实时模式：到今天当前时间
        } elseif ($period === 'day') {
            $endDate = now()->subDay()->endOfDay(); // 过去一天：昨天 23:59:59
        } elseif ($period === 'week') {
            $endDate = now()->subDay()->endOfDay(); // 过去一周：昨天 23:59:59
        } elseif ($period === 'month') {
            $endDate = now()->subDay()->endOfDay(); // 过去一月：昨天 23:59:59
        } else {
            $endDate = now(); // 默认：到现在
        }

        // 获取权限过滤条件
        $scenicSpotIds = null;
        if (!$request->user()->isAdmin()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
        }
        
        // 景区筛选（和权限取交集）
        if (!empty($selectedScenicSpotId)) {
            $selectedScenicSpotId = (int)$selectedScenicSpotId;
            if ($scenicSpotIds === null) {
                $scenicSpotIds = collect([$selectedScenicSpotId]);
            } else {
                $scenicSpotIds = $scenicSpotIds->intersect([$selectedScenicSpotId])->values();
            }
        }
        
        // 当前用户可筛选的景区列表
        $availableScenicSpotsQuery = ScenicSpot::query()->select('id', 'name');
        if (!$request->user()->isAdmin()) {
            $availableScenicSpotsQuery->whereIn('id', $scenicSpotIds ?? collect());
        }
        $availableScenicSpots = $availableScenicSpotsQuery->orderBy('name')->get();

        // 统计普通订单
        $orderQuery = Order::query();
        $this->applyDateRangeFilter($orderQuery, 'orders.' . $dateColumn, $startDate, $endDate, $dateType === 'arrival');
        if ($scenicSpotIds !== null) {
            $orderQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        if (!empty($selectedOtaPlatformId)) {
            $orderQuery->where('ota_platform_id', (int)$selectedOtaPlatformId);
        }

        // 统计打包订单
        $pkgOrderQuery = PkgOrder::query();
        $this->applyDateRangeFilter($pkgOrderQuery, 'pkg_orders.' . $dateColumn, $startDate, $endDate, $dateType === 'arrival');
        if ($scenicSpotIds !== null) {
            $pkgOrderQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        if (!empty($selectedOtaPlatformId)) {
            $pkgOrderQuery->where('ota_platform_id', (int)$selectedOtaPlatformId);
        }

        // 基础统计数据
        $orderStats = $this->getOrderStats($orderQuery);
        $pkgOrderStats = $this->getPkgOrderStats($pkgOrderQuery);

        // 合并统计数据
        $totalStats = [
            'total_orders' => $orderStats['total_orders'] + $pkgOrderStats['total_orders'],
            'total_amount' => (float)$orderStats['total_amount'] + (float)$pkgOrderStats['total_amount'],
            'total_settlement_amount' => (float)$orderStats['total_settlement_amount'] + (float)$pkgOrderStats['total_settlement_amount'],
            'verified_total_amount' => (float)$orderStats['verified_total_amount'],
            'confirmed_orders' => $orderStats['confirmed_orders'] + $pkgOrderStats['confirmed_orders'],
            'verified_orders' => $orderStats['verified_orders'],
            'cancelled_orders' => $orderStats['cancelled_orders'] + $pkgOrderStats['cancelled_orders'],
        ];

        // 按状态分布
        $statusDistribution = $this->getStatusDistribution($orderQuery, $pkgOrderQuery);

        // 按OTA平台分布
        $platformDistribution = $this->getPlatformDistribution($startDate, $scenicSpotIds, $endDate, $dateColumn, $dateType === 'arrival');

        // 时间趋势（按天统计）
        $timeTrend = $this->getTimeTrend($orderQuery, $pkgOrderQuery, $dateColumn);
        
        // 销售前十产品（按销售额降序）
        $topProducts = $this->getTopProducts($orderQuery, $pkgOrderQuery);

        return response()->json([
            'period' => $period,
            'date_type' => $dateType,
            'scenic_spot_id' => $selectedScenicSpotId,
            'ota_platform_id' => !empty($selectedOtaPlatformId) ? (int)$selectedOtaPlatformId : null,
            'start_date' => $startDate->format('Y-m-d H:i:s'),
            'end_date' => $endDate->format('Y-m-d H:i:s'),
            'available_scenic_spots' => $availableScenicSpots,
            'available_channels' => $this->getAvailableChannels(),
            'stats' => $totalStats,
            'order_stats' => $orderStats,
            'pkg_order_stats' => $pkgOrderStats,
            'status_distribution' => $statusDistribution,
            'platform_distribution' => $platformDistribution,
            'time_trend' => $timeTrend,
            'top_products' => $topProducts,
        ]);
    }

    /**
     * 获取普通订单统计
     */
    private function getOrderStats($query)
    {
        return [
            'total_orders' => (clone $query)->count(),
            'total_amount' => (float)(clone $query)->sum('total_amount') ?? 0,
            'total_settlement_amount' => (float)(clone $query)->sum('settlement_amount') ?? 0,
            'verified_total_amount' => (float)(clone $query)
                ->where('status', OrderStatus::VERIFIED->value)
                ->sum('total_amount') ?? 0,
            'confirmed_orders' => (clone $query)->where('status', OrderStatus::CONFIRMED->value)->count(),
            'verified_orders' => (clone $query)->where('status', OrderStatus::VERIFIED->value)->count(),
            'cancelled_orders' => (clone $query)->where('status', OrderStatus::CANCEL_APPROVED->value)->count(),
        ];
    }

    /**
     * 获取可选渠道列表（启用的OTA平台）
     */
    private function getAvailableChannels()
    {
        return OtaPlatform::query()
            ->where('is_active', true)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    /**
     * 获取打包订单统计
     */
    private function getPkgOrderStats($query)
    {
        return [
            'total_orders' => (clone $query)->count(),
            'total_amount' => (float)(clone $query)->sum('total_amount') ?? 0,
            'total_settlement_amount' => (float)(clone $query)->sum('settlement_amount') ?? 0,
            'confirmed_orders' => (clone $query)->where('status', PkgOrderStatus::CONFIRMED->value)->count(),
            'cancelled_orders' => (clone $query)->where('status', PkgOrderStatus::CANCELLED->value)->count(),
        ];
    }

    /**
     * 获取状态分布
     */
    private function getStatusDistribution($orderQuery, $pkgOrderQuery)
    {
        // 普通订单状态分布
        $orderStatuses = (clone $orderQuery)
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as total_amount'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                // 处理 status 可能是枚举实例或字符串值的情况
                if ($item->status instanceof OrderStatus) {
                    $statusValue = $item->status->value;
                    $statusEnum = $item->status;
                } else {
                    $statusValue = (string)$item->status;
                    $statusEnum = OrderStatus::from($statusValue);
                }
                
                return [
                    'status' => $statusValue,
                    'label' => $statusEnum->label(),
                    'count' => $item->count,
                    'total_amount' => (float)$item->total_amount ?? 0,
                    'type' => 'order',
                ];
            });

        // 打包订单状态分布
        $pkgOrderStatuses = (clone $pkgOrderQuery)
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_amount) as total_amount'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                // 处理 status 可能是枚举实例或字符串值的情况
                if ($item->status instanceof PkgOrderStatus) {
                    $statusValue = $item->status->value;
                    $statusEnum = $item->status;
                } else {
                    $statusValue = (string)$item->status;
                    $statusEnum = PkgOrderStatus::from($statusValue);
                }
                
                return [
                    'status' => $statusValue,
                    'label' => $statusEnum->label(),
                    'count' => $item->count,
                    'total_amount' => (float)$item->total_amount ?? 0,
                    'type' => 'pkg_order',
                ];
            });

        // 合并并计算占比
        $allStatuses = $orderStatuses->concat($pkgOrderStatuses);
        $totalCount = $allStatuses->sum('count');

        return $allStatuses->map(function ($item) use ($totalCount) {
            $item['percentage'] = $totalCount > 0 ? round(($item['count'] / $totalCount) * 100, 2) : 0;
            return $item;
        })->values();
    }

    /**
     * 获取OTA平台分布
     */
    private function getPlatformDistribution($startDate, $scenicSpotIds, $endDate = null, string $dateColumn = 'created_at', bool $useDateOnly = false)
    {
        $endDate = $endDate ?? now();
        
        // 普通订单平台分布 - 明确指定表名以避免列名不明确的问题
        $orderPlatformsQuery = Order::query();
        $this->applyDateRangeFilter($orderPlatformsQuery, 'orders.' . $dateColumn, $startDate, $endDate, $useDateOnly);
        
        // 应用权限过滤
        if ($scenicSpotIds !== null) {
            $orderPlatformsQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        
        $orderPlatforms = $orderPlatformsQuery
            ->join('ota_platforms', 'orders.ota_platform_id', '=', 'ota_platforms.id')
            ->select(
                'ota_platforms.id',
                'ota_platforms.name',
                DB::raw('count(*) as count'),
                DB::raw('sum(orders.total_amount) as total_amount'),
                DB::raw('sum(orders.settlement_amount) as settlement_amount')
            )
            ->groupBy('ota_platforms.id', 'ota_platforms.name')
            ->get();

        // 打包订单平台分布 - 明确指定表名以避免列名不明确的问题
        $pkgOrderPlatformsQuery = PkgOrder::query();
        $this->applyDateRangeFilter($pkgOrderPlatformsQuery, 'pkg_orders.' . $dateColumn, $startDate, $endDate, $useDateOnly);
        
        // 应用权限过滤
        if ($scenicSpotIds !== null) {
            $pkgOrderPlatformsQuery->whereHas('product', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }
        
        $pkgOrderPlatforms = $pkgOrderPlatformsQuery
            ->join('ota_platforms', 'pkg_orders.ota_platform_id', '=', 'ota_platforms.id')
            ->select(
                'ota_platforms.id',
                'ota_platforms.name',
                DB::raw('count(*) as count'),
                DB::raw('sum(pkg_orders.total_amount) as total_amount'),
                DB::raw('sum(pkg_orders.settlement_amount) as settlement_amount')
            )
            ->groupBy('ota_platforms.id', 'ota_platforms.name')
            ->get();

        // 合并相同平台的数据
        $platformMap = [];
        
        foreach ($orderPlatforms as $platform) {
            $id = $platform->id;
            if (!isset($platformMap[$id])) {
                $platformMap[$id] = [
                    'id' => $id,
                    'name' => $platform->name,
                    'count' => 0,
                    'total_amount' => 0,
                    'settlement_amount' => 0,
                ];
            }
            $platformMap[$id]['count'] += $platform->count;
            $platformMap[$id]['total_amount'] += (float)$platform->total_amount;
            $platformMap[$id]['settlement_amount'] += (float)$platform->settlement_amount;
        }

        foreach ($pkgOrderPlatforms as $platform) {
            $id = $platform->id;
            if (!isset($platformMap[$id])) {
                $platformMap[$id] = [
                    'id' => $id,
                    'name' => $platform->name,
                    'count' => 0,
                    'total_amount' => 0,
                    'settlement_amount' => 0,
                ];
            }
            $platformMap[$id]['count'] += $platform->count;
            $platformMap[$id]['total_amount'] += (float)$platform->total_amount;
            $platformMap[$id]['settlement_amount'] += (float)$platform->settlement_amount;
        }

        // 计算占比
        $totalCount = array_sum(array_column($platformMap, 'count'));
        $platforms = array_values($platformMap);
        foreach ($platforms as &$platform) {
            $platform['percentage'] = $totalCount > 0 ? round(($platform['count'] / $totalCount) * 100, 2) : 0;
        }

        return $platforms;
    }

    /**
     * 获取时间趋势（按天统计）
     */
    private function getTimeTrend($orderQuery, $pkgOrderQuery, string $dateColumn = 'created_at')
    {
        // 普通订单按天统计
        $orderTrend = (clone $orderQuery)
            ->select(
                DB::raw("DATE({$dateColumn}) as date"),
                DB::raw('count(*) as count'),
                DB::raw('sum(total_amount) as total_amount')
            )
            ->groupBy(DB::raw("DATE({$dateColumn})"))
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => $item->count,
                    'total_amount' => (float)$item->total_amount ?? 0,
                ];
            });

        // 打包订单按天统计
        $pkgOrderTrend = (clone $pkgOrderQuery)
            ->select(
                DB::raw("DATE({$dateColumn}) as date"),
                DB::raw('count(*) as count'),
                DB::raw('sum(total_amount) as total_amount')
            )
            ->groupBy(DB::raw("DATE({$dateColumn})"))
            ->orderBy('date')
            ->get()
            ->keyBy('date')
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => $item->count,
                    'total_amount' => (float)$item->total_amount ?? 0,
                ];
            });

        // 合并数据
        $allDates = array_unique(array_merge($orderTrend->keys()->toArray(), $pkgOrderTrend->keys()->toArray()));
        sort($allDates);

        $trend = [];
        foreach ($allDates as $date) {
            $orderData = $orderTrend->get($date, ['count' => 0, 'total_amount' => 0]);
            $pkgOrderData = $pkgOrderTrend->get($date, ['count' => 0, 'total_amount' => 0]);
            
            $trend[] = [
                'date' => $date,
                'count' => $orderData['count'] + $pkgOrderData['count'],
                'total_amount' => (float)$orderData['total_amount'] + (float)$pkgOrderData['total_amount'],
            ];
        }

        return $trend;
    }

    /**
     * 获取销售额排名前十产品（普通产品+打包产品）
     */
    private function getTopProducts($orderQuery, $pkgOrderQuery)
    {
        $orderProducts = (clone $orderQuery)
            ->join('products', 'orders.product_id', '=', 'products.id')
            ->select(
                DB::raw("'order' as product_type"),
                'products.id as product_id',
                'products.name as product_name',
                DB::raw('count(*) as order_count'),
                DB::raw('sum(orders.total_amount) as total_amount'),
                DB::raw('sum(orders.settlement_amount) as settlement_amount')
            )
            ->groupBy('products.id', 'products.name')
            ->get()
            ->map(function ($item) {
                return [
                    'product_type' => $item->product_type,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'order_count' => (int)$item->order_count,
                    'total_amount' => (float)$item->total_amount,
                    'settlement_amount' => (float)$item->settlement_amount,
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
                DB::raw('sum(pkg_orders.settlement_amount) as settlement_amount')
            )
            ->groupBy('pkg_products.id', 'pkg_products.product_name')
            ->get()
            ->map(function ($item) {
                return [
                    'product_type' => $item->product_type,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'order_count' => (int)$item->order_count,
                    'total_amount' => (float)$item->total_amount,
                    'settlement_amount' => (float)$item->settlement_amount,
                ];
            });

        return $orderProducts
            ->concat($pkgOrderProducts)
            ->sortByDesc('total_amount')
            ->take(10)
            ->values();
    }

    /**
     * 为查询应用时间范围过滤
     */
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

