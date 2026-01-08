<?php

namespace App\Http\Controllers\Test;

use App\Http\Controllers\Webhooks\MeituanController;
use App\Enums\OtaPlatform;
use App\Http\Client\MeituanClient;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * 美团测试控制器
 * 用于美团沙箱测试，临时替换正式环境的webhook路由
 * 
 * 注意：测试完成后，需要将路由改回指向 MeituanController
 */
class MeituanTestController extends MeituanController
{
    /**
     * 测试控制器标记
     */
    protected bool $isTestMode = true;

    /**
     * 重写handleOrder方法，添加测试日志
     */
    public function handleOrder(Request $request): Response
    {
        Log::info('美团测试控制器：收到请求', [
            'path' => $request->path(),
            'method' => $request->method(),
            'headers' => [
                'PartnerId' => $request->header('PartnerId'),
                'X-Encryption-Status' => $request->header('X-Encryption-Status'),
            ],
        ]);

        // 调用父类方法处理请求
        $response = parent::handleOrder($request);

        Log::info('美团测试控制器：响应完成', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * 重写handleProductLevelPriceCalendarV2方法，添加测试日志
     */
    public function handleProductLevelPriceCalendarV2(Request $request): Response
    {
        Log::info('美团测试控制器：收到价格日历V2请求', [
            'path' => $request->path(),
        ]);

        // 调用父类方法处理请求
        $response = parent::handleProductLevelPriceCalendarV2($request);

        Log::info('美团测试控制器：价格日历V2响应完成', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * 重写handleProductPriceCalendar方法，添加测试日志
     */
    public function handleProductPriceCalendar(Request $request): Response
    {
        Log::info('美团测试控制器：收到价格日历请求', [
            'path' => $request->path(),
        ]);

        // 调用父类方法处理请求
        $response = parent::handleProductPriceCalendar($request);

        Log::info('美团测试控制器：价格日历响应完成', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}


namespace App\Http\Controllers\Test;

use App\Http\Controllers\Webhooks\MeituanController;
use App\Enums\OtaPlatform;
use App\Http\Client\MeituanClient;
use App\Models\Order;
use App\Models\OtaPlatform as OtaPlatformModel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * 美团测试控制器
 * 用于美团沙箱测试，临时替换正式环境的webhook路由
 * 
 * 注意：测试完成后，需要将路由改回指向 MeituanController
 */
class MeituanTestController extends MeituanController
{
    /**
     * 测试控制器标记
     */
    protected bool $isTestMode = true;

    /**
     * 重写handleOrder方法，添加测试日志
     */
    public function handleOrder(Request $request): Response
    {
        Log::info('美团测试控制器：收到请求', [
            'path' => $request->path(),
            'method' => $request->method(),
            'headers' => [
                'PartnerId' => $request->header('PartnerId'),
                'X-Encryption-Status' => $request->header('X-Encryption-Status'),
            ],
        ]);

        // 调用父类方法处理请求
        $response = parent::handleOrder($request);

        Log::info('美团测试控制器：响应完成', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * 重写handleProductLevelPriceCalendarV2方法，添加测试日志
     */
    public function handleProductLevelPriceCalendarV2(Request $request): Response
    {
        Log::info('美团测试控制器：收到价格日历V2请求', [
            'path' => $request->path(),
        ]);

        // 调用父类方法处理请求
        $response = parent::handleProductLevelPriceCalendarV2($request);

        Log::info('美团测试控制器：价格日历V2响应完成', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * 重写handleProductPriceCalendar方法，添加测试日志
     */
    public function handleProductPriceCalendar(Request $request): Response
    {
        Log::info('美团测试控制器：收到价格日历请求', [
            'path' => $request->path(),
        ]);

        // 调用父类方法处理请求
        $response = parent::handleProductPriceCalendar($request);

        Log::info('美团测试控制器：价格日历响应完成', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}

