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
        Schema::create('res_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('res_hotels')->onDelete('restrict')->comment('所属酒店ID');
            $table->string('name')->comment('房型名称');
            $table->string('external_room_id', 100)->nullable()->comment('第三方房型ID（用于API对接时标识第三方房型）');
            
            // 房型属性
            $table->integer('max_occupancy')->default(2)->comment('最大入住人数');
            $table->string('bed_type', 50)->nullable()->comment('床型');
            $table->decimal('room_area', 5, 2)->nullable()->comment('房间面积（平方米）');
            $table->text('description')->nullable()->comment('房型描述');
            
            // 状态
            $table->boolean('is_active')->default(true)->comment('是否启用');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('hotel_id');
            $table->index('external_room_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('res_room_types');
    }
};




