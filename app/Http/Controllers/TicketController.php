<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /**
     * 门票列表
     */
    public function index(Request $request): JsonResponse
    {
        $query = Ticket::with(['scenicSpot', 'softwareProvider']);

        // 权限控制：运营只能查看所属资源方下的所有景区下的门票
        if ($request->user()->isOperator()) {
            $resourceProviderIds = $request->user()->resourceProviders->pluck('id');
            $scenicSpotIds = \App\Models\ScenicSpot::whereHas('resourceProviders', function ($query) use ($resourceProviderIds) {
                $query->whereIn('resource_providers.id', $resourceProviderIds);
            })->pluck('id');
            
            $query->whereIn('scenic_spot_id', $scenicSpotIds);
        }

        // 搜索功能
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('external_ticket_id', 'like', "%{$search}%");
            });
        }

        if ($request->has('scenic_spot_id')) {
            $query->where('scenic_spot_id', $request->scenic_spot_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // 排序
        $query->orderBy('created_at', 'desc');

        $tickets = $query->paginate($request->get('per_page', 15));

        return response()->json($tickets);
    }

    /**
     * 门票详情
     */
    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load(['scenicSpot', 'softwareProvider', 'prices']);
        
        return response()->json([
            'data' => $ticket,
        ]);
    }

    /**
     * 创建门票
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'required|exists:scenic_spots,id',
            'software_provider_id' => 'nullable|exists:software_providers,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:255|unique:tickets,code',
            'external_ticket_id' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $ticket = Ticket::create($validated);
        $ticket->load(['scenicSpot', 'softwareProvider']);

        return response()->json([
            'data' => $ticket,
            'message' => '门票创建成功',
        ], 201);
    }

    /**
     * 更新门票
     */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'scenic_spot_id' => 'sometimes|required|exists:scenic_spots,id',
            'software_provider_id' => 'nullable|exists:software_providers,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|nullable|string|max:255|unique:tickets,code,' . $ticket->id,
            'external_ticket_id' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $ticket->update($validated);
        $ticket->load(['scenicSpot', 'softwareProvider']);

        return response()->json([
            'data' => $ticket,
            'message' => '门票更新成功',
        ]);
    }

    /**
     * 删除门票
     */
    public function destroy(Ticket $ticket): JsonResponse
    {
        $ticket->delete();

        return response()->json([
            'message' => '门票删除成功',
        ]);
    }
}
