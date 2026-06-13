<?php

namespace App\Console\Commands;

use App\Services\Mp\MpPendingPaymentService;
use Illuminate\Console\Command;

class ExpireMpPendingBookingsCommand extends Command
{
    protected $signature = 'mp:expire-pending-bookings';

    protected $description = '取消超时未支付的小程序预约单，并恢复权益为待预约';

    public function handle(MpPendingPaymentService $service): int
    {
        $count = $service->expireAllOverdue();
        $this->info("已处理超时待支付预约 {$count} 笔");

        return self::SUCCESS;
    }
}
