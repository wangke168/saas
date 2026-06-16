<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scenic_spot_order_push_configs')) {
            return;
        }

        Schema::create('scenic_spot_order_push_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->unique()->constrained('scenic_spots')->cascadeOnDelete();
            $table->boolean('enabled')->default(false)->comment('是否向第三方推送订单');
            $table->string('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenic_spot_order_push_configs');
    }
};
