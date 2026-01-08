<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 每小时同步库存
        $schedule->command('sync:inventory')->hourly();
        
        // 每小时同步价格
        $schedule->command('sync:price')->hourly();
        
        // 每半小时同步OTA价格和库存
        $schedule->command('ota:sync-price-stock')->everyThirtyMinutes();
        
        // 每半小时同步美团价格和库存
        $schedule->command('meituan:sync-price-stock')->everyThirtyMinutes();
        
        // 每天清理过期订单（30天前）
        $schedule->call(function () {
            \App\Models\Order::where('created_at', '<', now()->subDays(30))
                ->whereIn('status', ['rejected', 'cancel_approved'])
                ->delete();
        })->daily();
        
        // 每天更新打包产品价格日历（确保未来60天都有价格）
        $schedule->call(function () {
            $products = \App\Models\Pkg\PkgProduct::where('status', 1)->get();
            foreach ($products as $product) {
                \App\Jobs\Pkg\UpdatePkgProductPriceJob::dispatch($product->id);
            }
        })->dailyAt('02:00'); // 每天凌晨2点执行

        // 每天凌晨2点查询订单核销状态
        $schedule->command('order:query-verification-status')->dailyAt('02:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
