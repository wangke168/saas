<?php

namespace App\Jobs;

use App\Models\Inventory;
use App\Models\RoomType;
use App\Services\Resource\HengdianService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncHengdianInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public RoomType $roomType,
        public string $date
    ) {}

    /**
     * Execute the job.
     */
    public function handle(HengdianService $hengdianService): void
    {
        // 同步横店库存
        // 具体实现根据横店接口文档
    }
}
