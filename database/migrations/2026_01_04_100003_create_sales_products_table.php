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
        Schema::create('sales_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->onDelete('restrict')->comment('所属景区ID');
            
            // 产品标识
            $table->string('ota_product_code', 50)->unique()->comment('OTA产品编码，格式：PKG_XXX');
            $table->string('product_name', 100)->comment('产品名称');
            $table->string('product_mode', 20)->default('SYSTEM_PKG')->comment('产品模式：SYSTEM_PKG=系统打包, LEGACY=旧业务');
            
            // 产品属性
            $table->integer('stay_days')->default(1)->comment('入住天数');
            $table->text('description')->nullable()->comment('产品描述');
            
            // 状态
            $table->integer('status')->default(1)->comment('1=上架, 0=下架');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('scenic_spot_id');
            $table->index('product_mode');
            $table->index('ota_product_code');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_products');
    }
};




