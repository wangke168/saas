<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('id_region_restriction_enabled')->default(false)->after('is_realname');
            $table->json('id_region_prefixes')->nullable()->after('id_region_restriction_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['id_region_restriction_enabled', 'id_region_prefixes']);
        });
    }
};
