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
        Schema::create('package_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_product_id')
                ->constrained('products')
                ->cascadeOnDelete()
                ->comment('打包产品ID（products表，product_type=package）');
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
                ->comment('资源服务类型');
            $table->boolean('is_active')
                ->default(true)
                ->comment('是否启用');
            $table->timestamps();

            // 索引
            $table->index('package_product_id');
            $table->index('ticket_product_id');
            $table->index('hotel_product_id');
            $table->index('hotel_id');
            $table->index('room_type_id');

            // 唯一索引：确保同一个（打包产品+门票+酒店+房型）组合不重复
            $table->unique(['package_product_id', 'ticket_product_id', 'hotel_product_id', 'hotel_id', 'room_type_id'], 'uk_package_ticket_hotel_room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_products');
    }
};

