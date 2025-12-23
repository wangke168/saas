<?php

namespace App\Console\Commands;

use App\Jobs\SyncHengdianInventoryJob;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Console\Command;

class SyncInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:inventory {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步资源方库存';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        
        $this->info("开始同步库存，日期：{$date}");

        // 获取所有直连的酒店
        $hotels = Hotel::where('is_connected', true)
            ->whereHas('resourceProvider', function ($query) {
                $query->where('api_type', 'hengdian');
            })
            ->get();

        foreach ($hotels as $hotel) {
            foreach ($hotel->roomTypes as $roomType) {
                SyncHengdianInventoryJob::dispatch($roomType, $date);
            }
        }

        $this->info('库存同步任务已提交到队列');

        return Command::SUCCESS;
    }
}
