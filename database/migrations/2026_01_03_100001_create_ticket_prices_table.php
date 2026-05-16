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
        if (Schema::hasTable('ticket_prices')) {
            return;
        }
        
        Schema::create('ticket_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade')->comment('门票ID');
            $table->date('date')->comment('日期');
            $table->decimal('market_price', 10, 2)->default(0)->comment('门市价');
            $table->decimal('sale_price', 10, 2)->default(0)->comment('销售价');
            $table->decimal('settlement_price', 10, 2)->default(0)->comment('结算价');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['ticket_id', 'date']);
            $table->index('ticket_id');
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_prices');
    }
};




