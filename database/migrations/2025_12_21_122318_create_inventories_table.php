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
        if (Schema::hasTable('inventories')) {
            return;
        }
        
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete()->comment('房型ID');
            $table->date('date')->comment('日期');
            $table->integer('total_quantity')->default(0)->comment('总库存');
            $table->integer('available_quantity')->default(0)->comment('可用库存');
            $table->integer('locked_quantity')->default(0)->comment('锁定库存');
            $table->string('source')->default('manual')->comment('库存来源: manual=人工维护, api=接口推送');
            $table->boolean('is_closed')->default(false)->comment('是否关闭（人工关闭）');
            $table->timestamps();
            
            $table->unique(['room_type_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
