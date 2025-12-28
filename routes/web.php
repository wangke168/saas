<?php

use Illuminate\Support\Facades\Route;

Route::prefix('manage')->group(function () {
    // 排除静态资源路径（assets、build），只处理应用路由
    Route::get('/{any}', function () {
        return view('app');
    })->where('any', '^(?!assets|build).*$');
});
