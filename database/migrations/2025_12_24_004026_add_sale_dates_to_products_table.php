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
            $table->date('sale_start_date')->nullable()->after('stay_days')->comment('销售开始日期');
            $table->date('sale_end_date')->nullable()->after('sale_start_date')->comment('销售结束日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sale_start_date', 'sale_end_date']);
        });
    }
};
