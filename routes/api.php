<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Webhooks路由（无需认证）
Route::prefix('webhooks')->group(function () {
    Route::post('/ctrip', [\App\Http\Controllers\Webhooks\CtripController::class, 'handleOrder']);
    Route::post('/fliggy/product-change', [\App\Http\Controllers\Webhooks\FliggyController::class, 'productChange']);
    Route::post('/fliggy/order-status', [\App\Http\Controllers\Webhooks\FliggyController::class, 'orderStatus']);
});

// 认证相关路由（无需认证）
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/test', [\App\Http\Controllers\TestLoginController::class, 'test']); // 测试接口
});

// 需要认证的路由
Route::middleware('auth:sanctum')->group(function () {
    // 认证相关
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'user']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    // 用户管理（仅超级管理员）
    Route::prefix('users')->middleware('role:admin')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::put('/{user}', [UserController::class, 'update']);
        Route::post('/{user}/disable', [UserController::class, 'disable']);
        Route::post('/{user}/enable', [UserController::class, 'enable']);
    });

    // 景区管理（仅超级管理员）
    Route::prefix('scenic-spots')->middleware('role:admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\ScenicSpotController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ScenicSpotController::class, 'store']);
        Route::get('/{scenicSpot}', [\App\Http\Controllers\ScenicSpotController::class, 'show']);
        Route::put('/{scenicSpot}', [\App\Http\Controllers\ScenicSpotController::class, 'update']);
        Route::delete('/{scenicSpot}', [\App\Http\Controllers\ScenicSpotController::class, 'destroy']);
    });

    // 软件商管理（仅超级管理员）
    Route::prefix('software-providers')->middleware('role:admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\SoftwareProviderController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\SoftwareProviderController::class, 'store']);
        Route::put('/{softwareProvider}', [\App\Http\Controllers\SoftwareProviderController::class, 'update']);
        Route::delete('/{softwareProvider}', [\App\Http\Controllers\SoftwareProviderController::class, 'destroy']);
    });

    // 资源方管理（只读，用于下拉选择）
    Route::prefix('software-providers')->group(function () {
        Route::get('/', [\App\Http\Controllers\SoftwareProviderController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\SoftwareProviderController::class, 'store']);
        Route::get('/{softwareProvider}', [\App\Http\Controllers\SoftwareProviderController::class, 'show']);
        Route::put('/{softwareProvider}', [\App\Http\Controllers\SoftwareProviderController::class, 'update']);
        Route::delete('/{softwareProvider}', [\App\Http\Controllers\SoftwareProviderController::class, 'destroy']);
    });

    // 酒店管理
    Route::prefix('hotels')->group(function () {
        Route::get('/', [\App\Http\Controllers\HotelController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\HotelController::class, 'store']);
        Route::get('/{hotel}', [\App\Http\Controllers\HotelController::class, 'show']);
        Route::put('/{hotel}', [\App\Http\Controllers\HotelController::class, 'update']);
        Route::delete('/{hotel}', [\App\Http\Controllers\HotelController::class, 'destroy']);
    });

    // 房型管理
    Route::prefix('room-types')->group(function () {
        Route::get('/', [\App\Http\Controllers\RoomTypeController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\RoomTypeController::class, 'store']);
        Route::put('/{roomType}', [\App\Http\Controllers\RoomTypeController::class, 'update']);
        Route::delete('/{roomType}', [\App\Http\Controllers\RoomTypeController::class, 'destroy']);
    });

    // 库存管理
    Route::prefix('inventories')->group(function () {
        Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\InventoryController::class, 'store']);
        Route::put('/{inventory}', [\App\Http\Controllers\InventoryController::class, 'update']);
        Route::post('/{inventory}/close', [\App\Http\Controllers\InventoryController::class, 'close']);
        Route::post('/{inventory}/open', [\App\Http\Controllers\InventoryController::class, 'open']);
    });

    // 产品管理
    Route::prefix('products')->group(function () {
        Route::get('/', [\App\Http\Controllers\ProductController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ProductController::class, 'store']);
        // 导出路由需要在通用路由之前，避免路由冲突
        Route::get('/{product}/export', [\App\Http\Controllers\ProductController::class, 'export']);
        // OTA绑定
        Route::post('/{product}/bind-ota', [\App\Http\Controllers\OtaProductController::class, 'bindOta']);
        // 携程同步
        Route::post('/{product}/sync-price-to-ctrip', [\App\Http\Controllers\CtripSyncController::class, 'syncPrice']);
        Route::post('/{product}/sync-stock-to-ctrip', [\App\Http\Controllers\CtripSyncController::class, 'syncStock']);
        // 通用路由放在最后
        Route::get('/{product}', [\App\Http\Controllers\ProductController::class, 'show']);
        Route::put('/{product}', [\App\Http\Controllers\ProductController::class, 'update']);
        Route::delete('/{product}', [\App\Http\Controllers\ProductController::class, 'destroy']);
    });

    // OTA产品管理
    Route::prefix('ota-products')->group(function () {
        Route::post('/{otaProduct}/push', [\App\Http\Controllers\OtaProductController::class, 'push']);
        Route::put('/{otaProduct}', [\App\Http\Controllers\OtaProductController::class, 'update']);
        Route::delete('/{otaProduct}', [\App\Http\Controllers\OtaProductController::class, 'destroy']);
    });

    // 价格管理
    Route::prefix('prices')->group(function () {
        Route::get('/', [\App\Http\Controllers\PriceController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\PriceController::class, 'store']);
        Route::put('/{price}', [\App\Http\Controllers\PriceController::class, 'update']);
        Route::delete('/{price}', [\App\Http\Controllers\PriceController::class, 'destroy']);
    });

    // 加价规则管理
    Route::prefix('price-rules')->group(function () {
        Route::get('/', [\App\Http\Controllers\PriceRuleController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\PriceRuleController::class, 'store']);
        Route::get('/{priceRule}', [\App\Http\Controllers\PriceRuleController::class, 'show']);
        Route::put('/{priceRule}', [\App\Http\Controllers\PriceRuleController::class, 'update']);
        Route::delete('/{priceRule}', [\App\Http\Controllers\PriceRuleController::class, 'destroy']);
    });

    // 库存管理
    Route::prefix('inventories')->group(function () {
        Route::get('/', [\App\Http\Controllers\InventoryController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\InventoryController::class, 'store']);
        Route::put('/{inventory}', [\App\Http\Controllers\InventoryController::class, 'update']);
        Route::post('/{inventory}/close', [\App\Http\Controllers\InventoryController::class, 'close']);
        Route::post('/{inventory}/open', [\App\Http\Controllers\InventoryController::class, 'open']);
    });

    // 订单管理
    Route::prefix('orders')->group(function () {
        Route::get('/', [\App\Http\Controllers\OrderController::class, 'index']);
        Route::get('/{order}', [\App\Http\Controllers\OrderController::class, 'show']);
        Route::post('/{order}/update-status', [\App\Http\Controllers\OrderController::class, 'updateStatus']);
    });

    // 异常订单处理
    Route::prefix('exception-orders')->group(function () {
        Route::get('/', [\App\Http\Controllers\ExceptionOrderController::class, 'index']);
        Route::get('/{exceptionOrder}', [\App\Http\Controllers\ExceptionOrderController::class, 'show']);
        Route::post('/{exceptionOrder}/start-processing', [\App\Http\Controllers\ExceptionOrderController::class, 'startProcessing']);
        Route::post('/{exceptionOrder}/resolve', [\App\Http\Controllers\ExceptionOrderController::class, 'resolve']);
    });

    // OTA平台管理（只读）
    Route::prefix('ota-platforms')->group(function () {
        Route::get('/', [\App\Http\Controllers\OtaPlatformController::class, 'index']);
        Route::get('/{otaPlatform}', [\App\Http\Controllers\OtaPlatformController::class, 'show']);
    });
});

