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
        // 先删除外键约束
        if (Schema::hasColumn('orders', 'resource_provider_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['resource_provider_id']);
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'resource_provider_id')) {
                $table->dropColumn('resource_provider_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('resource_provider_id')->nullable()->after('resource_order_no')->comment('资源方ID');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('resource_provider_id')
                ->references('id')
                ->on('resource_providers')
                ->onDelete('set null');
        });
    }
};
