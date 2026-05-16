<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_realname')->nullable()->comment('是否实名制（用于决定美团 realNameType/credentialList）');
        });

        Schema::table('pkg_products', function (Blueprint $table) {
            $table->boolean('is_realname')->nullable()->comment('是否实名制（用于决定美团 realNameType/credentialList）');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_realname');
        });

        Schema::table('pkg_products', function (Blueprint $table) {
            $table->dropColumn('is_realname');
        });
    }
};

