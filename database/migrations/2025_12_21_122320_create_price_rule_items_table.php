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
        if (Schema::hasTable('price_rule_items')) {
            return;
        }
        
        Schema::create('price_rule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_rule_id')->constrained('price_rules')->cascadeOnDelete()->comment('加价规则ID');
            $table->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete()->comment('酒店ID');
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete()->comment('房型ID');
            $table->timestamps();
            
            $table->unique(['price_rule_id', 'hotel_id', 'room_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_rule_items');
    }
};
