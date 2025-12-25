<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 如果 resource_providers 表存在且有数据，迁移到 software_providers
        if (Schema::hasTable('resource_providers')) {
            $resourceProviders = DB::table('resource_providers')->get();
            
            foreach ($resourceProviders as $provider) {
                // 检查 software_providers 中是否已存在相同 code 的记录
                $exists = DB::table('software_providers')
                    ->where('code', $provider->code)
                    ->exists();
                
                if (!$exists) {
                    // 插入到 software_providers
                    DB::table('software_providers')->insert([
                        'name' => $provider->name,
                        'code' => $provider->code,
                        'description' => $provider->description,
                        'api_type' => $provider->api_type ?? 'other',
                        'is_active' => $provider->is_active ?? true,
                        'created_at' => $provider->created_at ?? now(),
                        'updated_at' => $provider->updated_at ?? now(),
                    ]);
                }
            }

            // 迁移 resource_configs 的关联关系
            // 注意：这里需要先更新 resource_configs 表的外键，但该表会在下一个迁移中修改
            // 所以这里只迁移数据，外键修改在下一个迁移中处理
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚时不需要特殊处理，因为 resource_providers 表会被删除
    }
};
