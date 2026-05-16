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
        Schema::create('scenic_spot_software_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scenic_spot_id')->comment('景区ID');
            $table->unsignedBigInteger('software_provider_id')->comment('软件服务商ID');
            $table->timestamps();
            
            // 唯一约束：一个景区不能重复添加同一个服务商
            $table->unique(['scenic_spot_id', 'software_provider_id'], 'unique_scenic_spot_software_provider');
            
            // 外键约束
            $table->foreign('scenic_spot_id', 'scenic_spot_software_providers_scenic_spot_id_foreign')
                  ->references('id')
                  ->on('scenic_spots')
                  ->onDelete('cascade');
            
            $table->foreign('software_provider_id', 'scenic_spot_software_providers_software_provider_id_foreign')
                  ->references('id')
                  ->on('software_providers')
                  ->onDelete('cascade');
            
            // 索引
            $table->index('scenic_spot_id');
            $table->index('software_provider_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenic_spot_software_providers');
    }
};
