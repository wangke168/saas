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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('order_provider_id')->nullable()->after('order_mode')
                ->constrained('software_providers')->nullOnDelete()
                ->comment('订单下发服务商ID（当order_mode为other时使用，null=使用景区配置）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['order_provider_id']);
            $table->dropColumn('order_provider_id');
        });
    }
};
