<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationReportRequest;
use App\Services\OperationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OperationReportController extends Controller
{
    public function __construct(
        private readonly OperationReportService $operationReportService
    ) {}

    public function index(OperationReportRequest $request): JsonResponse
    {
        return response()->json($this->operationReportService->buildReport($request));
    }

    public function export(OperationReportRequest $request): Response
    {
        return $this->operationReportService->export($request);
    }
}
