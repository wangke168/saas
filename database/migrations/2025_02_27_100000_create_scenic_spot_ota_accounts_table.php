<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 景区-OTA-账号映射：仅 account/partnerId 按景区区分，其余配置平台共用
     */
    public function up(): void
    {
        if (Schema::hasTable('scenic_spot_ota_accounts')) {
            return;
        }

        Schema::create('scenic_spot_ota_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->unsignedBigInteger('ota_platform_id')->comment('OTA平台ID');
            $table->string('account', 64)->comment('该景区在该平台的账号：携程ACCOUNT_ID，美团PARTNER_ID');
            $table->timestamps();

            $table->unique(['scenic_spot_id', 'ota_platform_id'], 'scenic_spot_ota_accounts_spot_platform_unique');
            $table->index(['ota_platform_id', 'account'], 'scenic_spot_ota_accounts_platform_account_index');

            $table->foreign('scenic_spot_id')->references('id')->on('scenic_spots')->onDelete('cascade');
            $table->foreign('ota_platform_id')->references('id')->on('ota_platforms')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenic_spot_ota_accounts');
    }
};
