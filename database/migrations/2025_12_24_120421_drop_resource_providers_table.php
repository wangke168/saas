<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 注意：删除表前需要确保所有外键约束都已移除
        // 这个迁移应该在所有其他迁移完成后执行
        
        if (Schema::hasTable('resource_providers')) {
            // 检查是否还有外键引用
            // 如果 resource_configs 表已经修改完成，可以安全删除
            Schema::dropIfExists('resource_providers');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 回滚时重新创建 resource_providers 表
        if (!Schema::hasTable('resource_providers')) {
            Schema::create('resource_providers', function (Blueprint $table) {
                $table->id();
                $table->string('name')->comment('资源方名称');
                $table->string('code')->unique()->comment('资源方编码');
                $table->text('description')->nullable()->comment('描述');
                $table->string('api_type')->comment('接口类型: hengdian=横店影视城');
                $table->boolean('is_active')->default(true)->comment('是否启用');
                $table->timestamps();
            });
        }
    }
};
