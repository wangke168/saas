<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 补全 orders 拆单字段（兼容生产库已执行旧迁移但缺列的情况）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'parent_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('parent_order_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('orders')
                    ->nullOnDelete()
                    ->comment('父订单ID');
            });
        }

        if (! Schema::hasColumn('orders', 'order_type')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('order_type', ['main', 'ticket', 'hotel'])
                    ->default('main')
                    ->after(Schema::hasColumn('orders', 'parent_order_id') ? 'parent_order_id' : 'id')
                    ->comment('订单类型: main/ticket/hotel');
            });
        }

        if (! Schema::hasColumn('orders', 'ticket_product_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('ticket_product_id')
                    ->nullable()
                    ->after('order_type')
                    ->constrained('products')
                    ->nullOnDelete()
                    ->comment('门票产品ID');
            });
        }

        if (! Schema::hasColumn('orders', 'related_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('related_order_id')
                    ->nullable()
                    ->after('ticket_product_id')
                    ->constrained('orders')
                    ->nullOnDelete()
                    ->comment('关联订单ID');
            });
        }

        if (Schema::hasTable('order_bookings') && Schema::hasColumn('order_bookings', 'fulfilled_order_id')) {
            DB::table('orders')
                ->whereNotNull('parent_order_id')
                ->whereIn('id', function ($query): void {
                    $query->select('fulfilled_order_id')
                        ->from('order_bookings')
                        ->whereNotNull('fulfilled_order_id');
                })
                ->update(['order_type' => 'hotel']);
        }

        DB::table('orders')
            ->whereNotNull('parent_order_id')
            ->where('ota_order_no', 'like', '%-TICKET')
            ->update(['order_type' => 'ticket']);

        DB::table('orders')
            ->whereNotNull('parent_order_id')
            ->where('ota_order_no', 'like', '%-HOTEL')
            ->update(['order_type' => 'hotel']);

        DB::table('orders')
            ->whereNull('parent_order_id')
            ->where('order_type', 'main')
            ->update(['order_type' => 'main']);
    }

    public function down(): void
    {
        // 生产补列迁移不回滚
    }
};
