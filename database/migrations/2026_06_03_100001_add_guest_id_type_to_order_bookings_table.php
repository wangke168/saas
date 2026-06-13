<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_bookings', function (Blueprint $table) {
            $table->string('guest_id_type', 16)
                ->default('id_card')
                ->after('guest_phone')
                ->comment('id_card=身份证, passport=护照');
        });
    }

    public function down(): void
    {
        Schema::table('order_bookings', function (Blueprint $table) {
            $table->dropColumn('guest_id_type');
        });
    }
};
