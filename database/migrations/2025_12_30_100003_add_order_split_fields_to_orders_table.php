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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('parent_order_id')
                ->nullable()
                ->after('id')
                ->constrained('orders')
                ->nullOnDelete()
                ->comment('父订单ID（打包订单的子订单使用）');
            
            $table->enum('order_type', ['main', 'ticket', 'hotel'])
                ->default('main')
                ->after('parent_order_id')
                ->comment('订单类型: main=主订单, ticket=门票子订单, hotel=酒店子订单');
            
            $table->foreignId('ticket_product_id')
                ->nullable()
                ->after('order_type')
                ->constrained('products')
                ->nullOnDelete()
                ->comment('门票产品ID（主订单和门票子订单使用）');
            
            $table->foreignId('related_order_id')
                ->nullable()
                ->after('ticket_product_id')
                ->constrained('orders')
                ->nullOnDelete()
                ->comment('关联订单ID（门票订单关联酒店订单，反之亦然）');

            // 索引
            $table->index('parent_order_id');
            $table->index('order_type');
            $table->index('related_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['parent_order_id']);
            $table->dropForeign(['ticket_product_id']);
            $table->dropForeign(['related_order_id']);
            $table->dropIndex(['parent_order_id']);
            $table->dropIndex(['order_type']);
            $table->dropIndex(['related_order_id']);
            $table->dropColumn(['parent_order_id', 'order_type', 'ticket_product_id', 'related_order_id']);
        });
    }
};




