<?php

namespace App\Console\Commands;

use App\Services\InventoryFilterService;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Jobs\ProcessResourceInventoryPushJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 测试库存推送流程
 * 
 * 用法：
 * php artisan test:inventory-push --hotel=001 --room-type=标准间 --date=2025-12-27 --quantity=100
 */
class TestInventoryPush extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:inventory-push 
                            {--hotel= : 酒店编号（external_code或code）}
                            {--room-type= : 房型名称（external_code或name）}
                            {--date= : 日期（Y-m-d格式）}
                            {--quantity= : 库存数量}
                            {--async : 是否使用异步处理（默认false）}
                            {--raw : 直接测试原始XML数据}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试库存推送流程（支持同步和异步两种模式）';

    /**
     * Execute the console command.
     */
    public function handle(InventoryFilterService $inventoryFilterService): int
    {
        $this->info('开始测试库存推送流程...');

        // 如果使用原始XML数据
        if ($this->option('raw')) {
            return $this->testRawXml();
        }

        // 获取参数
        $hotelNo = $this->option('hotel');
        $roomType = $this->option('room-type');
        $date = $this->option('date') ?: date('Y-m-d');
        $quantity = (int)($this->option('quantity') ?: 100);
        $useAsync = $this->option('async');

        if (!$hotelNo || !$roomType) {
            $this->error('请提供酒店编号和房型名称');
            $this->info('用法: php artisan test:inventory-push --hotel=001 --room-type=标准间 --date=2025-12-27 --quantity=100');
            return Command::FAILURE;
        }

        // 查找酒店和房型
        $hotel = Hotel::where('external_code', $hotelNo)
            ->orWhere('code', $hotelNo)
            ->first();

        if (!$hotel) {
            $this->error("未找到酒店：{$hotelNo}");
            return Command::FAILURE;
        }

        $roomTypeModel = RoomType::where('hotel_id', $hotel->id)
            ->where(function($query) use ($roomType) {
                $query->where('external_code', $roomType)
                      ->orWhere('name', $roomType);
            })
            ->first();

        if (!$roomTypeModel) {
            $this->error("未找到房型：{$roomType}（酒店：{$hotelNo}）");
            return Command::FAILURE;
        }

        $this->info("找到酒店：{$hotel->name} (ID: {$hotel->id})");
        $this->info("找到房型：{$roomTypeModel->name} (ID: {$roomTypeModel->id})");
        $this->info("测试日期：{$date}");
        $this->info("测试库存：{$quantity}");

        // 构建测试数据
        $testData = [
            [
                'hotelNo' => $hotelNo,
                'roomType' => $roomType,
                'roomQuota' => [
                    [
                        'date' => $date,
                        'quota' => $quantity,
                    ]
                ]
            ]
        ];

        // 构建XML
        $xml = $this->buildXml($testData);

        $this->info("\n生成的XML数据：");
        $this->line($xml);

        // 测试 Redis 过滤
        $this->info("\n测试 Redis 指纹比对...");
        $itemsToCheck = [
            [
                'hotel_id' => $hotel->id,
                'room_type_id' => $roomTypeModel->id,
                'date' => $date,
                'quantity' => $quantity,
            ]
        ];

        $changed = $inventoryFilterService->filterChanged($itemsToCheck);
        
        if (empty($changed)) {
            $this->warn('Redis 比对结果：无变化（库存值相同）');
        } else {
            $this->info('Redis 比对结果：有变化，需要更新');
            $this->info('变化项数量：' . count($changed));
        }

        // 测试推送
        if ($useAsync) {
            $this->info("\n使用异步处理模式...");
            ProcessResourceInventoryPushJob::dispatch($xml)
                ->onQueue('resource-push');
            $this->info('已放入队列，请查看队列日志');
        } else {
            $this->info("\n使用同步处理模式...");
            $this->info('提示：同步处理会直接更新数据库，请谨慎使用');
            
            if ($this->confirm('确认继续？', true)) {
                // 直接调用 Controller 方法
                $controller = app(\App\Http\Controllers\Webhooks\ResourceController::class);
                $request = \Illuminate\Http\Request::create('/test', 'POST', [], [], [], [], $xml);
                $response = $controller->handleHengdianInventory($request);
                
                $this->info('处理完成');
                $this->info('响应：' . $response->getContent());
            }
        }

        return Command::SUCCESS;
    }

    /**
     * 测试原始XML数据
     */
    protected function testRawXml(): int
    {
        $this->info('请输入XML数据（输入完成后按 Ctrl+D）：');
        $xml = '';
        while (($line = fgets(STDIN)) !== false) {
            $xml .= $line;
        }

        if (empty($xml)) {
            $this->error('XML数据为空');
            return Command::FAILURE;
        }

        $this->info("\n接收到的XML数据：");
        $this->line($xml);

        // 使用异步处理
        ProcessResourceInventoryPushJob::dispatch($xml)
            ->onQueue('resource-push');

        $this->info('已放入队列，请查看队列日志');
        return Command::SUCCESS;
    }

    /**
     * 构建XML数据
     */
    protected function buildXml(array $data): string
    {
        $roomQuotaMapJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $xml = new \SimpleXMLElement('<RoomStatus></RoomStatus>');
        $xml->addChild('RoomQuotaMap', $roomQuotaMapJson);
        
        return $xml->asXML();
    }
}

