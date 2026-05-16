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
        Schema::create('product_fliggy_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->comment('本地产品ID');
            $table->unsignedBigInteger('hotel_id')->nullable()->comment('本地酒店ID');
            $table->unsignedBigInteger('room_type_id')->nullable()->comment('本地房型ID');
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->string('fliggy_product_id')->comment('飞猪产品ID');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
            
            $table->index(['product_id', 'hotel_id', 'room_type_id'], 'idx_product_hotel_room');
            $table->index('fliggy_product_id', 'idx_fliggy_product_id');
            $table->index('scenic_spot_id', 'idx_scenic_spot_id');
            $table->index('is_active', 'idx_is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_fliggy_mappings');
    }
};
