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
        Schema::create('product_hotel_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_product_id')
                ->constrained('products')
                ->cascadeOnDelete()
                ->comment('门票产品ID');
            $table->foreignId('hotel_product_id')
                ->constrained('products')
                ->cascadeOnDelete()
                ->comment('酒店产品ID');
            $table->foreignId('hotel_id')
                ->constrained('hotels')
                ->cascadeOnDelete()
                ->comment('酒店ID');
            $table->foreignId('room_type_id')
                ->constrained('room_types')
                ->cascadeOnDelete()
                ->comment('房型ID');
            $table->string('resource_service_type', 50)
                ->nullable()
                ->comment('资源服务类型（用于标识酒店对接的系统）');
            $table->boolean('is_active')
                ->default(true)
                ->comment('是否启用');
            $table->integer('sort_order')
                ->default(0)
                ->comment('排序');
            $table->timestamps();

            // 索引
            $table->index('ticket_product_id');
            $table->index('hotel_product_id');
            $table->index('hotel_id');
            $table->index('room_type_id');

            // 唯一索引：确保同一个（门票+酒店+房型）组合不重复
            $table->unique(['ticket_product_id', 'hotel_product_id', 'hotel_id', 'room_type_id'], 'uk_ticket_hotel_room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_hotel_relations');
    }
};




