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
        if (Schema::hasTable('user_resource_providers')) {
            return;
        }
        
        Schema::create('user_resource_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resource_provider_id')->constrained('resource_providers')->cascadeOnDelete();
            $table->timestamps();
            
            // 手动指定索引名称，避免名称过长
            $table->unique(['user_id', 'resource_provider_id'], 'user_rp_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_resource_providers');
    }
};
