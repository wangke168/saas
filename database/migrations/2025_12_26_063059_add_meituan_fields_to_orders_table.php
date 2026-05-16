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
            $table->tinyInteger('real_name_type')->default(0)->after('guest_info')
                ->comment('实名制订单标识：0=非实名制，1=实名制');
            $table->json('credential_list')->nullable()->after('real_name_type')
                ->comment('证件号和凭证码的对应关系');
            $table->string('refund_serial_no')->nullable()->after('cancelled_at')
                ->comment('退款流水号，用于幂等性检查');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['real_name_type', 'credential_list', 'refund_serial_no']);
        });
    }
};
