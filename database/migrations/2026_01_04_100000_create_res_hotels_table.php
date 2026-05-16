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
        Schema::create('res_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->onDelete('restrict')->comment('所属景区ID');
            $table->foreignId('software_provider_id')->nullable()->constrained('software_providers')->onDelete('set null')->comment('所属软件服务商ID（NULL=自控库存，有值=第三方库存，通过软件服务商API对接）');
            $table->string('name')->comment('酒店名称');
            
            // 外部标识（仅第三方有效，通过软件服务商API对接时使用）
            $table->string('external_hotel_id', 100)->nullable()->comment('第三方酒店ID（用于API对接）');
            
            // 基础信息
            $table->string('address')->nullable()->comment('酒店地址');
            $table->string('contact_phone', 20)->nullable()->comment('联系电话');
            $table->text('description')->nullable()->comment('酒店描述');
            
            // 状态
            $table->boolean('is_active')->default(true)->comment('是否启用');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('scenic_spot_id');
            $table->index('software_provider_id');
            $table->index('external_hotel_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('res_hotels');
    }
};




