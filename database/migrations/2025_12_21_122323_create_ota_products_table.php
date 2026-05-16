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
        if (Schema::hasTable('ota_products')) {
            return;
        }
        
        Schema::create('ota_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->comment('产品ID');
            $table->foreignId('ota_platform_id')->constrained('ota_platforms')->cascadeOnDelete()->comment('OTA平台ID');
            $table->string('ota_product_id')->nullable()->comment('OTA平台产品ID');
            $table->boolean('is_active')->default(true)->comment('是否推送');
            $table->timestamp('pushed_at')->nullable()->comment('推送时间');
            $table->timestamps();
            
            $table->unique(['product_id', 'ota_platform_id']);
            $table->index('ota_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ota_products');
    }
};
