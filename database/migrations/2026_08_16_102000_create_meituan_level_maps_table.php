<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meituan_level_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->restrictOnDelete()->comment('景区ID（冗余，便于按景区查询）');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete()->comment('产品ID');
            $table->unsignedBigInteger('level_id')->comment('美团 levelId');
            $table->unsignedTinyInteger('level_no')->comment('1=酒店层 2=房型层');
            $table->foreignId('hotel_id')->nullable()->constrained('hotels')->nullOnDelete()->comment('酒店ID（仅酒店层）');
            $table->string('level_name', 191)->comment('酒店名或房型名');
            $table->timestamps();

            $table->unique(['product_id', 'level_id'], 'meituan_level_maps_product_level_unique');
            $table->index('scenic_spot_id');
            $table->index(['product_id', 'level_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meituan_level_maps');
    }
};
