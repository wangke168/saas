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
        if (Schema::hasTable('resource_provider_scenic_spots')) {
            return;
        }
        
        Schema::create('resource_provider_scenic_spots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_provider_id')->constrained('resource_providers')->cascadeOnDelete();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->cascadeOnDelete();
            $table->timestamps();
            
            // 手动指定索引名称，避免名称过长
            $table->unique(['resource_provider_id', 'scenic_spot_id'], 'rp_ss_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_provider_scenic_spots');
    }
};
