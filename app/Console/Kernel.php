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
        
        // 每天清理过期订单（30天前）
        $schedule->call(function () {
            \App\Models\Order::where('created_at', '<', now()->subDays(30))
                ->whereIn('status', ['rejected', 'cancel_approved'])
                ->delete();
        })->daily();
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

