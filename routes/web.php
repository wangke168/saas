<?php

use Illuminate\Support\Facades\Route;

Route::prefix('manage')->group(function () {
    Route::get('/{any}', function () {
        return view('app');
    })->where('any', '.*');
});
