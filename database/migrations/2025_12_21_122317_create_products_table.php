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
        if (Schema::hasTable('products')) {
            return;
        }
        
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->cascadeOnDelete()->comment('所属景区ID');
            $table->string('name')->comment('产品名称');
            $table->string('code')->unique()->comment('产品编码');
            $table->text('description')->nullable()->comment('产品描述');
            $table->string('price_source')->default('manual')->comment('价格来源: manual=人工维护, api=接口推送');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->index('scenic_spot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
