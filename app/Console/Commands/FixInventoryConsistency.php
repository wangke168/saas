<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 修复库存数据一致性问题
 * 
 * 修复以下问题：
 * 1. available_quantity > total_quantity
 * 2. total_quantity = 0 但 available_quantity > 0
 * 3. total_quantity = 0 但 locked_quantity > 0
 */
class FixInventoryConsistency extends Command
{
    protected $signature = 'inventory:fix-consistency 
                            {--dry-run : 只检查不修复}
                            {--room-type-id= : 只修复指定房型的库存}';

    protected $description = '修复库存数据一致性问题';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $roomTypeId = $this->option('room-type-id');

        $this->info('开始检查库存数据一致性...');

        $query = Inventory::query();
        
        if ($roomTypeId) {
            $query->where('room_type_id', $roomTypeId);
        }

        // 查找不一致的数据
        $inconsistencies = $query->where(function ($q) {
            // available_quantity > total_quantity
            $q->whereRaw('available_quantity > total_quantity')
              // total_quantity = 0 但 available_quantity > 0
              ->orWhere(function ($subQ) {
                  $subQ->where('total_quantity', 0)
                       ->where('available_quantity', '>', 0);
              })
              // total_quantity = 0 但 locked_quantity > 0
              ->orWhere(function ($subQ) {
                  $subQ->where('total_quantity', 0)
                       ->where('locked_quantity', '>', 0);
              });
        })->get();

        if ($inconsistencies->isEmpty()) {
            $this->info('未发现数据不一致问题。');
            return 0;
        }

        $this->warn("发现 {$inconsistencies->count()} 条不一致的数据：");

        $fixedCount = 0;
        $skippedCount = 0;

        foreach ($inconsistencies as $inventory) {
            $this->line("  - 房型ID: {$inventory->room_type_id}, 日期: {$inventory->date->format('Y-m-d')}");
            $this->line("    总库存: {$inventory->total_quantity}, 可用库存: {$inventory->available_quantity}, 锁定库存: {$inventory->locked_quantity}");

            if ($isDryRun) {
                $this->line("    [DRY RUN] 将修复为: 总库存={$inventory->total_quantity}, 可用库存=" . min($inventory->available_quantity, $inventory->total_quantity) . ", 锁定库存=" . ($inventory->total_quantity === 0 ? 0 : $inventory->locked_quantity));
                $skippedCount++;
                continue;
            }

            try {
                DB::beginTransaction();

                $updates = [];
                
                // 修复 available_quantity 超过 total_quantity 的问题
                if ($inventory->available_quantity > $inventory->total_quantity) {
                    $updates['available_quantity'] = $inventory->total_quantity;
                }
                
                // 修复 total_quantity = 0 但 available_quantity > 0 的问题
                if ($inventory->total_quantity === 0 && $inventory->available_quantity > 0) {
                    $updates['available_quantity'] = 0;
                }
                
                // 修复 total_quantity = 0 但 locked_quantity > 0 的问题
                if ($inventory->total_quantity === 0 && $inventory->locked_quantity > 0) {
                    $updates['locked_quantity'] = 0;
                }

                if (!empty($updates)) {
                    $inventory->update($updates);
                    
                    Log::info('库存数据一致性修复', [
                        'inventory_id' => $inventory->id,
                        'room_type_id' => $inventory->room_type_id,
                        'date' => $inventory->date->format('Y-m-d'),
                        'updates' => $updates,
                        'before' => [
                            'total_quantity' => $inventory->getOriginal('total_quantity'),
                            'available_quantity' => $inventory->getOriginal('available_quantity'),
                            'locked_quantity' => $inventory->getOriginal('locked_quantity'),
                        ],
                        'after' => [
                            'total_quantity' => $inventory->total_quantity,
                            'available_quantity' => $inventory->available_quantity,
                            'locked_quantity' => $inventory->locked_quantity,
                        ],
                    ]);

                    $this->info("    ✓ 已修复");
                    $fixedCount++;
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("    ✗ 修复失败: " . $e->getMessage());
                Log::error('库存数据一致性修复失败', [
                    'inventory_id' => $inventory->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isDryRun) {
            $this->info("\n[DRY RUN] 共发现 {$inconsistencies->count()} 条需要修复的数据。");
            $this->info("运行命令时去掉 --dry-run 选项来执行修复。");
        } else {
            $this->info("\n修复完成！");
            $this->info("修复: {$fixedCount} 条");
            if ($skippedCount > 0) {
                $this->warn("跳过: {$skippedCount} 条");
            }
        }

        return 0;
    }
}

