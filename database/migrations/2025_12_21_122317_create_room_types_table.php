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
        if (Schema::hasTable('room_types')) {
            return;
        }
        
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete()->comment('所属酒店ID');
            $table->string('name')->comment('房型名称');
            $table->string('code')->comment('房型编码');
            $table->integer('max_occupancy')->default(2)->comment('最大入住人数');
            $table->text('description')->nullable()->comment('房型描述');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->index(['hotel_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
