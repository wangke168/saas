<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('fulfillment_mode', 20)
                ->default('immediate')
                ->after('product_type')
                ->comment('履约模式: immediate=落单即履约, deferred=小程序预约后履约');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index('fulfillment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_mode']);
            $table->dropColumn('fulfillment_mode');
        });
    }
};
