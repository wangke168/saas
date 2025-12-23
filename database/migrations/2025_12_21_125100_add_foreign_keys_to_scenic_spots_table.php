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
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->foreign('software_provider_id')
                ->references('id')
                ->on('software_providers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scenic_spots', function (Blueprint $table) {
            $table->dropForeign(['software_provider_id']);
        });
    }
};
