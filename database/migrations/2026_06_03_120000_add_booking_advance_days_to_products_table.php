<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('booking_advance_days')
                ->default(0)
                ->after('fulfillment_mode')
                ->comment('预售预约提前天数：0=不限制；N>0 表示入住日最早为今天+N（仅 deferred 生效）');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('booking_advance_days');
        });
    }
};
