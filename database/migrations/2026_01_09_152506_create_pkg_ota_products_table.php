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
        if (Schema::hasTable('pkg_ota_products')) {
            return;
        }
        
        Schema::create('pkg_ota_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pkg_product_id')
                ->constrained('pkg_products')
                ->cascadeOnDelete()
                ->comment('打包产品ID');
            $table->foreignId('ota_platform_id')
                ->constrained('ota_platforms')
                ->cascadeOnDelete()
                ->comment('OTA平台ID');
            $table->string('ota_product_id')->nullable()->comment('OTA平台产品ID');
            $table->boolean('is_active')->default(true)->comment('是否推送');
            $table->timestamp('pushed_at')->nullable()->comment('推送时间');
            // 推送状态字段（在创建表时一起添加，参照 ota_products 表的完整结构）
            // 注意：创建表时不需要使用 after()，字段顺序由定义顺序决定
            $table->string('push_status', 20)->nullable()->comment('推送状态：pending/processing/success/failed');
            $table->timestamp('push_started_at')->nullable()->comment('推送开始时间');
            $table->timestamp('push_completed_at')->nullable()->comment('推送完成时间');
            $table->text('push_message')->nullable()->comment('推送消息');
            $table->timestamps();
            
            // 索引（完全参照 ota_products 表）
            $table->unique(['pkg_product_id', 'ota_platform_id']);
            $table->index('ota_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pkg_ota_products');
    }
};
