<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Webhooks路由（无需认证）
Route::prefix('webhooks')->group(function () {
    Route::post('/ctrip', [\App\Http\Controllers\Webhooks\CtripController::class, 'handleOrder']);
    Route::post('/fliggy/product-change', [\App\Http\Controllers\Webhooks\FliggyController::class, 'productChange']);
    Route::post('/fliggy/order-status', [\App\Http\Controllers\Webhooks\FliggyController::class, 'orderStatus']);
    
    // 美团Webhook路由
    Route::post('/meituan/order/create/v2', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleOrder']);
    Route::post('/meituan/order/pay', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleOrder']);
    Route::post('/meituan/order/query', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleOrder']);
    Route::post('/meituan/order/refund', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleOrder']);
    Route::post('/meituan/order/refunded', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleOrder']);
    Route::post('/meituan/order/close', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleOrder']);
    
    // 美团产品相关路由（拉取价格日历等）
    Route::post('/meituan/product/price/calendar', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleProductPriceCalendar']);
    Route::post('/meituan/product/level/price/calendar/v2', [\App\Http\Controllers\Webhooks\MeituanController::class, 'handleProductLevelPriceCalendarV2']);
    
    // 资源方Webhook路由
    Route::post('/resource/hengdian/inventory', [\App\Http\Controllers\Webhooks\ResourceController::class, 'handleHengdianInventory']);
    
    // 测试接口（仅开发环境使用）
    if (app()->environment(['local', 'testing'])) {
        Route::post('/test/resource-inventory-push', [\App\Http\Controllers\Webhooks\ResourceController::class, 'handleHengdianInventory']);
    }
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

    // 资源方管理（仅超级管理员）
    Route::prefix('resource-providers')->middleware('role:admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\ResourceProviderController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ResourceProviderController::class, 'store']);
        Route::get('/{resourceProvider}', [\App\Http\Controllers\ResourceProviderController::class, 'show']);
        Route::put('/{resourceProvider}', [\App\Http\Controllers\ResourceProviderController::class, 'update']);
        Route::delete('/{resourceProvider}', [\App\Http\Controllers\ResourceProviderController::class, 'destroy']);
        Route::post('/{resourceProvider}/attach-scenic-spots', [\App\Http\Controllers\ResourceProviderController::class, 'attachScenicSpots']);
        Route::post('/{resourceProvider}/scenic-spots', [\App\Http\Controllers\ResourceProviderController::class, 'attachScenicSpots']);
    });

    // 景区管理（仅超级管理员）
    Route::prefix('scenic-spots')->middleware('role:admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\ScenicSpotController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ScenicSpotController::class, 'store']);
        Route::put('/{scenicSpot}', [\App\Http\Controllers\ScenicSpotController::class, 'update']);
        Route::delete('/{scenicSpot}', [\App\Http\Controllers\ScenicSpotController::class, 'destroy']);
        
        // 资源配置
        Route::get('/{scenicSpot}/resource-config', [\App\Http\Controllers\ResourceConfigController::class, 'show']);
        Route::post('/{scenicSpot}/resource-config', [\App\Http\Controllers\ResourceConfigController::class, 'store']);
        Route::post('/{scenicSpot}/resource-config/subscribe-inventory', [\App\Http\Controllers\ResourceConfigController::class, 'subscribeInventory']);
    });

    // 景区详情（所有已认证用户可访问，但需要权限检查）
    Route::prefix('scenic-spots')->group(function () {
        Route::get('/{scenicSpot}', [\App\Http\Controllers\ScenicSpotController::class, 'show']);
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
        // 库存推送到OTA
        Route::post('/{inventory}/push-to-ota', [\App\Http\Controllers\InventoryPushController::class, 'pushInventory']);
        Route::post('/batch-push-to-ota', [\App\Http\Controllers\InventoryPushController::class, 'batchPushInventory']);
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
        // 订单操作
        Route::post('/{order}/confirm', [\App\Http\Controllers\OrderController::class, 'confirmOrder']);
        Route::post('/{order}/reject', [\App\Http\Controllers\OrderController::class, 'rejectOrder']);
        Route::post('/{order}/verify', [\App\Http\Controllers\OrderController::class, 'verifyOrder']);
        Route::post('/{order}/approve-cancel', [\App\Http\Controllers\OrderController::class, 'approveCancel']);
        Route::post('/{order}/reject-cancel', [\App\Http\Controllers\OrderController::class, 'rejectCancel']);
    });

    // 异常订单处理
    Route::prefix('exception-orders')->group(function () {
        Route::get('/', [\App\Http\Controllers\ExceptionOrderController::class, 'index']);
        Route::get('/{exceptionOrder}', [\App\Http\Controllers\ExceptionOrderController::class, 'show']);
        Route::post('/{exceptionOrder}/start-processing', [\App\Http\Controllers\ExceptionOrderController::class, 'startProcessing']);
        Route::post('/{exceptionOrder}/resolve', [\App\Http\Controllers\ExceptionOrderController::class, 'resolve']);
    });

// 门票管理
Route::prefix('tickets')->group(function () {
    Route::get('/', [\App\Http\Controllers\TicketController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\TicketController::class, 'store']);
    Route::get('/{ticket}', [\App\Http\Controllers\TicketController::class, 'show']);
    Route::put('/{ticket}', [\App\Http\Controllers\TicketController::class, 'update']);
    Route::delete('/{ticket}', [\App\Http\Controllers\TicketController::class, 'destroy']);
});

    // 门票价库管理
    Route::prefix('ticket-prices')->middleware('role:admin,operator')->group(function () {
        Route::get('/', [\App\Http\Controllers\TicketPriceController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\TicketPriceController::class, 'store']);
        Route::post('/batch', [\App\Http\Controllers\TicketPriceController::class, 'batchStore']);
        Route::put('/{ticketPrice}', [\App\Http\Controllers\TicketPriceController::class, 'update']);
        Route::delete('/{ticketPrice}', [\App\Http\Controllers\TicketPriceController::class, 'destroy']);
    });

    // 打包酒店管理
    Route::prefix('res-hotels')->group(function () {
        Route::get('/', [\App\Http\Controllers\ResHotelController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ResHotelController::class, 'store']);
        Route::get('/{resHotel}', [\App\Http\Controllers\ResHotelController::class, 'show']);
        Route::put('/{resHotel}', [\App\Http\Controllers\ResHotelController::class, 'update']);
        Route::delete('/{resHotel}', [\App\Http\Controllers\ResHotelController::class, 'destroy']);
    });

    // 打包房型管理
    Route::prefix('res-room-types')->group(function () {
        Route::get('/', [\App\Http\Controllers\ResRoomTypeController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ResRoomTypeController::class, 'store']);
        Route::get('/{resRoomType}', [\App\Http\Controllers\ResRoomTypeController::class, 'show']);
        Route::put('/{resRoomType}', [\App\Http\Controllers\ResRoomTypeController::class, 'update']);
        Route::delete('/{resRoomType}', [\App\Http\Controllers\ResRoomTypeController::class, 'destroy']);
    });

    // 打包价库管理
    Route::prefix('res-hotel-daily-stocks')->group(function () {
        Route::get('/', [\App\Http\Controllers\ResHotelDailyStockController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ResHotelDailyStockController::class, 'store']);
        Route::post('/batch', [\App\Http\Controllers\ResHotelDailyStockController::class, 'batchStore']); // 批量设置
        Route::put('/{resHotelDailyStock}', [\App\Http\Controllers\ResHotelDailyStockController::class, 'update']);
        Route::delete('/{resHotelDailyStock}', [\App\Http\Controllers\ResHotelDailyStockController::class, 'destroy']);
    });

    // 打包产品管理
    Route::prefix('pkg-products')->group(function () {
        Route::get('/', [\App\Http\Controllers\PkgProductController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\PkgProductController::class, 'store']);
        Route::get('/{pkgProduct}', [\App\Http\Controllers\PkgProductController::class, 'show']);
        Route::put('/{pkgProduct}', [\App\Http\Controllers\PkgProductController::class, 'update']);
        Route::delete('/{pkgProduct}', [\App\Http\Controllers\PkgProductController::class, 'destroy']);
        
        // 价格管理路由（需要操作员或管理员权限）
        Route::prefix('{pkgProduct}/prices')->middleware('role:admin,operator')->group(function () {
            Route::post('/calculate', [\App\Http\Controllers\PkgProductPriceController::class, 'calculate']);
            Route::post('/recalculate', [\App\Http\Controllers\PkgProductPriceController::class, 'recalculate']);
            Route::post('/sync-to-ota', [\App\Http\Controllers\PkgProductPriceController::class, 'syncToOta']);
            Route::get('/calendar', [\App\Http\Controllers\PkgProductPriceController::class, 'getPriceCalendar']);
        });
    });

    // OTA平台管理（只读）
    // OTA平台公开接口（用于产品绑定时的下拉选择）
    Route::prefix('ota-platforms')->group(function () {
        Route::get('/', [\App\Http\Controllers\OtaPlatformController::class, 'index']);
        Route::get('/{otaPlatform}', [\App\Http\Controllers\OtaPlatformController::class, 'show']);
    });

    // OTA平台管理接口（仅超级管理员）
    Route::prefix('admin/ota-platforms')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\OtaPlatformController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Admin\OtaPlatformController::class, 'store']);
        
        // OTA配置管理（必须在/{otaPlatform}之前，避免路由冲突）
        Route::get('/{otaPlatform}/config', [\App\Http\Controllers\Admin\OtaConfigController::class, 'show']);
        Route::post('/{otaPlatform}/config', [\App\Http\Controllers\Admin\OtaConfigController::class, 'store']);
        
        // OTA平台CRUD
        Route::get('/{otaPlatform}', [\App\Http\Controllers\Admin\OtaPlatformController::class, 'show']);
        Route::put('/{otaPlatform}', [\App\Http\Controllers\Admin\OtaPlatformController::class, 'update']);
        Route::delete('/{otaPlatform}', [\App\Http\Controllers\Admin\OtaPlatformController::class, 'destroy']);
        
        // OTA配置更新和删除（使用config ID）
        Route::put('/config/{otaConfig}', [\App\Http\Controllers\Admin\OtaConfigController::class, 'update']);
        Route::delete('/config/{otaConfig}', [\App\Http\Controllers\Admin\OtaConfigController::class, 'destroy']);
    });
});
