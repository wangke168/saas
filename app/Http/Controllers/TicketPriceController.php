<?php

namespace App\Http\Controllers;

use App\Models\TicketPrice;
use App\Models\Ticket;
use App\Models\ScenicSpot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TicketPriceController extends Controller
{

    /**
     * 价库列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = TicketPrice::with(['ticket.scenicSpot']);

        if ($request->has('ticket_id')) {
            $query->where('ticket_id', $request->ticket_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        // 权限控制：运营只能查看所属资源方下的所有景区下的门票的价库
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereHas('ticket', function ($q) use ($scenicSpotIds) {
                $q->whereIn('scenic_spot_id', $scenicSpotIds);
            });
        }

        $prices = $query->orderBy('date')->paginate($request->get('per_page', 50));

        return response()->json($prices);
    }

    /**
     * 创建单个价库记录
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket_id' => 'required|exists:tickets,id',
            'date' => 'required|date_format:Y-m-d',
            'cost_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0|gte:cost_price',
            'stock_available' => 'required|integer|min:0',
        ]);

        // 权限控制：运营只能在自己所属资源方下的景区下的门票中创建价库
        if ($request->user()->isOperator()) {
            $ticket = Ticket::with('scenicSpot')->findOrFail($validated['ticket_id']);
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($ticket->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权在该门票下创建价库',
                ], 403);
            }
        }

        $price = TicketPrice::updateOrCreate(
            [
                'ticket_id' => $validated['ticket_id'],
                'date' => $validated['date'],
            ],
            $validated
        );

        $price->load(['ticket.scenicSpot']);

        return response()->json([
            'data' => $price,
            'message' => '价库记录创建成功',
        ], 201);
    }

    /**
     * 批量创建/更新价库记录
     */
    public function batchStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ticket_id' => 'required|exists:tickets,id',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'cost_price' => 'required|numeric|min:0',
            'sale_price' => 'required|numeric|min:0|gte:cost_price',
            'stock_available' => 'required|integer|min:0',
        ]);

        // 权限控制：运营只能在自己所属资源方下的景区下的门票中批量创建价库
        if ($request->user()->isOperator()) {
            $ticket = Ticket::with('scenicSpot')->findOrFail($validated['ticket_id']);
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            if (! $scenicSpotIds->contains($ticket->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权在该门票下批量创建价库',
                ], 403);
            }
        }

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $pricesToUpsert = [];

        while ($startDate->lte($endDate)) {
            $pricesToUpsert[] = [
                'ticket_id' => $validated['ticket_id'],
                'date' => $startDate->format('Y-m-d'),
                'cost_price' => $validated['cost_price'],
                'sale_price' => $validated['sale_price'],
                'stock_available' => $validated['stock_available'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $startDate->addDay();
        }

        DB::beginTransaction();
        try {
            TicketPrice::upsert(
                $pricesToUpsert,
                ['ticket_id', 'date'], // 唯一键
                ['cost_price', 'sale_price', 'stock_available', 'updated_at'] // 更新字段
            );
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('批量设置价库失败', ['error' => $e->getMessage(), 'data' => $pricesToUpsert]);
            return response()->json(['message' => '批量设置价库失败: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => '价库批量设置成功',
        ]);
    }

    /**
     * 更新单个价库记录
     */
    public function update(Request $request, TicketPrice $ticketPrice): JsonResponse
    {
        // 权限控制：运营只能更新自己绑定的景区下的门票的价库
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $ticketPrice->load('ticket');
            if (! $scenicSpotIds->contains($ticketPrice->ticket->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权更新该价库',
                ], 403);
            }
        }

        $validated = $request->validate([
            'cost_price' => 'sometimes|required|numeric|min:0',
            'sale_price' => 'sometimes|required|numeric|min:0|gte:cost_price',
            'stock_available' => 'sometimes|required|integer|min:0',
        ]);

        // 确保销售价不低于成本价
        if (isset($validated['cost_price']) && isset($validated['sale_price'])) {
            if ($validated['sale_price'] < $validated['cost_price']) {
                return response()->json(['message' => '销售价不能低于成本价'], 422);
            }
        } elseif (isset($validated['cost_price'])) {
            if ($ticketPrice->sale_price < $validated['cost_price']) {
                return response()->json(['message' => '销售价不能低于成本价'], 422);
            }
        } elseif (isset($validated['sale_price'])) {
            if ($validated['sale_price'] < $ticketPrice->cost_price) {
                return response()->json(['message' => '销售价不能低于成本价'], 422);
            }
        }

        $ticketPrice->update($validated);
        $ticketPrice->load(['ticket.scenicSpot']);

        return response()->json([
            'data' => $ticketPrice,
            'message' => '价库记录更新成功',
        ]);
    }

    /**
     * 删除单个价库记录
     */
    public function destroy(Request $request, TicketPrice $ticketPrice): JsonResponse
    {
        // 权限控制
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $ticketPrice->load('ticket');
            if (! $scenicSpotIds->contains($ticketPrice->ticket->scenic_spot_id)) {
                return response()->json([
                    'message' => '无权删除该价库',
                ], 403);
            }
        }

        $ticketPrice->delete();

        return response()->json([
            'message' => '价库记录删除成功',
        ]);
    }
}
