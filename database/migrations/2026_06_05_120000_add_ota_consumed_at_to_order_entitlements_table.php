<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_entitlements', function (Blueprint $table) {
            $table->timestamp('ota_consumed_at')
                ->nullable()
                ->after('booked_at')
                ->comment('已向 OTA 通知核销的时间（幂等）');
        });
    }

    public function down(): void
    {
        Schema::table('order_entitlements', function (Blueprint $table) {
            $table->dropColumn('ota_consumed_at');
        });
    }
};
