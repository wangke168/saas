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
        Schema::table('pkg_product_bundle_items', function (Blueprint $table) {
            // 添加外键约束（tickets表创建后）
            $table->foreign('ticket_id')
                ->references('id')
                ->on('tickets')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pkg_product_bundle_items', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
        });
    }
};
