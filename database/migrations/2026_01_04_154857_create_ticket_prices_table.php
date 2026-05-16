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
            $table->decimal('sale_price', 10, 2)->comment('销售价');
            $table->decimal('cost_price', 10, 2)->comment('成本价/结算价');
            $table->integer('stock_available')->nullable()->comment('可用库存（NULL表示无限）');
            $table->timestamps();
            $table->softDeletes()->comment('软删除');
            
            $table->unique(['ticket_id', 'date'], 'uk_ticket_date');
            $table->index('ticket_id', 'idx_ticket');
            $table->index('date', 'idx_date');
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
