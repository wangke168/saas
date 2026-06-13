<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('cover_image', 500)->nullable()->after('description')->comment('小程序头图 storage 相对路径');
            $table->json('booking_rules')->nullable()->after('cover_image')->comment('小程序预约规则，JSON 字符串数组');
            $table->text('mp_content')->nullable()->after('booking_rules')->comment('小程序产品内容');
            $table->string('fee_note', 500)->nullable()->after('mp_content')->comment('小程序费用说明');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['cover_image', 'booking_rules', 'mp_content', 'fee_note']);
        });
    }
};
