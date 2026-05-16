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
        Schema::table('scenic_spot_ota_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('scenic_spot_ota_accounts', 'secret_key')) {
                $table->string('secret_key', 255)->nullable()->after('account');
            }
            if (!Schema::hasColumn('scenic_spot_ota_accounts', 'aes_key')) {
                $table->string('aes_key', 255)->nullable()->after('secret_key');
            }
            if (!Schema::hasColumn('scenic_spot_ota_accounts', 'aes_iv')) {
                $table->string('aes_iv', 255)->nullable()->after('aes_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scenic_spot_ota_accounts', function (Blueprint $table) {
            $dropColumns = [];
            if (Schema::hasColumn('scenic_spot_ota_accounts', 'secret_key')) {
                $dropColumns[] = 'secret_key';
            }
            if (Schema::hasColumn('scenic_spot_ota_accounts', 'aes_key')) {
                $dropColumns[] = 'aes_key';
            }
            if (Schema::hasColumn('scenic_spot_ota_accounts', 'aes_iv')) {
                $dropColumns[] = 'aes_iv';
            }
            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
