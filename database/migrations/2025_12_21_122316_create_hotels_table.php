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
        if (Schema::hasTable('hotels')) {
            return;
        }
        
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->cascadeOnDelete()->comment('所属景区ID');
            $table->string('name')->comment('酒店名称');
            $table->string('code')->comment('酒店编码');
            $table->string('address')->nullable()->comment('酒店地址');
            $table->string('contact_phone')->nullable()->comment('联系电话');
            $table->boolean('is_connected')->default(false)->comment('是否直连（true=系统直连, false=手工处理）');
            $table->unsignedBigInteger('resource_provider_id')->nullable()->comment('资源方ID');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->index(['scenic_spot_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
