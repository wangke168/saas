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
        Schema::table('products', function (Blueprint $table) {
            // 添加外部产品编码字段（可选，用于和景区系统对接）
            $table->string('external_code')->nullable()->after('code')->comment('外部产品编码（用于和景区系统对接，如横店）');
            
            // 修改 code 字段为可空（因为要自动生成）
            $table->string('code')->nullable()->change();
            
            // 添加 external_code 的索引（用于搜索）
            $table->index('external_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // 删除索引
            $table->dropIndex(['external_code']);
            
            // 删除 external_code 字段
            $table->dropColumn('external_code');
            
            // 恢复 code 字段为必填（注意：这可能会导致数据问题，如果现有数据中有空值）
            // 实际回滚时，需要确保所有产品的 code 都不为空
            $table->string('code')->nullable(false)->change();
        });
    }
};