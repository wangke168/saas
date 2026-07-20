<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'parent_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('parent_order_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('orders')
                    ->nullOnDelete()
                    ->comment('父订单ID（打包订单的子订单使用）');
            });
        }

        if (! Schema::hasColumn('orders', 'order_type')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('order_type', ['main', 'ticket', 'hotel'])
                    ->default('main')
                    ->after('parent_order_id')
                    ->comment('订单类型: main=主订单, ticket=门票子订单, hotel=酒店子订单');
            });
        }

        if (! Schema::hasColumn('orders', 'ticket_product_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('ticket_product_id')
                    ->nullable()
                    ->after('order_type')
                    ->constrained('products')
                    ->nullOnDelete()
                    ->comment('门票产品ID（主订单和门票子订单使用）');
            });
        }

        if (! Schema::hasColumn('orders', 'related_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('related_order_id')
                    ->nullable()
                    ->after('ticket_product_id')
                    ->constrained('orders')
                    ->nullOnDelete()
                    ->comment('关联订单ID（门票订单关联酒店订单，反之亦然）');
            });
        }

        $this->ensureIndex('orders', 'orders_parent_order_id_index', ['parent_order_id']);
        $this->ensureIndex('orders', 'orders_order_type_index', ['order_type']);
        $this->ensureIndex('orders', 'orders_related_order_id_index', ['related_order_id']);

        $this->backfillOrderTypes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'related_order_id')) {
                $table->dropForeign(['related_order_id']);
                $table->dropIndex(['related_order_id']);
                $table->dropColumn('related_order_id');
            }

            if (Schema::hasColumn('orders', 'ticket_product_id')) {
                $table->dropForeign(['ticket_product_id']);
                $table->dropColumn('ticket_product_id');
            }

            if (Schema::hasColumn('orders', 'order_type')) {
                $table->dropIndex(['order_type']);
                $table->dropColumn('order_type');
            }

            if (Schema::hasColumn('orders', 'parent_order_id')) {
                $table->dropForeign(['parent_order_id']);
                $table->dropIndex(['parent_order_id']);
                $table->dropColumn('parent_order_id');
            }
        });
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        // 使用框架的跨数据库实现（原 SHOW INDEX 语句仅兼容 MySQL）
        if (Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->index($columns);
        });
    }

    private function backfillOrderTypes(): void
    {
        if (! Schema::hasColumn('orders', 'order_type')) {
            return;
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
            ->update(['order_type' => 'main']);
    }
};
