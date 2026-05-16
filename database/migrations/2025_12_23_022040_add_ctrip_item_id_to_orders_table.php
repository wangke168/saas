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
        Schema::table('orders', function (Blueprint $table) {
            // 添加携程订单项编号字段，用于存储 PayPreOrder 请求中传递的 itemId
            $table->string('ctrip_item_id')->nullable()->after('ota_order_no')->comment('携程订单项编号（PayPreOrder 请求中的 itemId）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ctrip_item_id');
        });
    }
};
