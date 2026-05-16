<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 将所有价格字段从"分"转换为"元"（除以100）
     */
    public function up(): void
    {
        // 1. prices 表
        if (Schema::hasTable('prices')) {
            DB::statement('UPDATE prices SET 
                market_price = market_price / 100,
                settlement_price = settlement_price / 100,
                sale_price = sale_price / 100
                WHERE market_price > 0 OR settlement_price > 0 OR sale_price > 0');
        }

        // 2. ticket_prices 表
        if (Schema::hasTable('ticket_prices')) {
            DB::statement('UPDATE ticket_prices SET 
                sale_price = sale_price / 100,
                cost_price = cost_price / 100
                WHERE sale_price > 0 OR cost_price > 0');
        }

        // 3. pkg_product_daily_prices 表
        if (Schema::hasTable('pkg_product_daily_prices')) {
            DB::statement('UPDATE pkg_product_daily_prices SET 
                sale_price = sale_price / 100,
                cost_price = cost_price / 100
                WHERE sale_price > 0 OR cost_price > 0');
        }

        // 4. res_hotel_daily_stock 表
        if (Schema::hasTable('res_hotel_daily_stock')) {
            DB::statement('UPDATE res_hotel_daily_stock SET 
                sale_price = sale_price / 100,
                cost_price = cost_price / 100
                WHERE sale_price > 0 OR cost_price > 0');
        }

        // 5. sales_product_prices 表
        if (Schema::hasTable('sales_product_prices')) {
            DB::statement('UPDATE sales_product_prices SET 
                sale_price = sale_price / 100,
                settlement_price = settlement_price / 100
                WHERE sale_price > 0 OR settlement_price > 0');
        }

        // 6. price_rules 表
        if (Schema::hasTable('price_rules')) {
            DB::statement('UPDATE price_rules SET 
                market_price_adjustment = market_price_adjustment / 100,
                settlement_price_adjustment = settlement_price_adjustment / 100,
                sale_price_adjustment = sale_price_adjustment / 100
                WHERE market_price_adjustment != 0 OR settlement_price_adjustment != 0 OR sale_price_adjustment != 0');
        }

        // 7. orders 表
        if (Schema::hasTable('orders')) {
            DB::statement('UPDATE orders SET 
                total_amount = total_amount / 100,
                settlement_amount = settlement_amount / 100
                WHERE total_amount > 0 OR settlement_amount > 0');
        }

        // 8. order_items 表
        if (Schema::hasTable('order_items')) {
            DB::statement('UPDATE order_items SET 
                unit_price = unit_price / 100,
                total_price = total_price / 100
                WHERE unit_price > 0 OR total_price > 0');
        }

        // 9. pkg_orders 表
        if (Schema::hasTable('pkg_orders')) {
            DB::statement('UPDATE pkg_orders SET 
                total_amount = total_amount / 100,
                settlement_amount = settlement_amount / 100
                WHERE total_amount > 0 OR settlement_amount > 0');
        }

        // 10. pkg_order_items 表
        if (Schema::hasTable('pkg_order_items')) {
            DB::statement('UPDATE pkg_order_items SET 
                unit_price = unit_price / 100,
                total_price = total_price / 100
                WHERE unit_price > 0 OR total_price > 0');
        }

        // 11. system_pkg_orders 表
        if (Schema::hasTable('system_pkg_orders')) {
            DB::statement('UPDATE system_pkg_orders SET 
                total_amount = total_amount / 100,
                settlement_amount = settlement_amount / 100
                WHERE total_amount > 0 OR settlement_amount > 0');
        }

        // 12. system_pkg_order_items 表
        if (Schema::hasTable('system_pkg_order_items')) {
            DB::statement('UPDATE system_pkg_order_items SET 
                unit_price = unit_price / 100,
                total_price = total_price / 100
                WHERE unit_price > 0 OR total_price > 0');
        }

        // 13. tickets 表（默认价格字段）
        if (Schema::hasTable('tickets')) {
            if (Schema::hasColumn('tickets', 'market_price')) {
                DB::statement('UPDATE tickets SET 
                    market_price = market_price / 100
                    WHERE market_price > 0');
            }
            if (Schema::hasColumn('tickets', 'sale_price')) {
                DB::statement('UPDATE tickets SET 
                    sale_price = sale_price / 100
                    WHERE sale_price > 0');
            }
            if (Schema::hasColumn('tickets', 'settlement_price')) {
                DB::statement('UPDATE tickets SET 
                    settlement_price = settlement_price / 100
                    WHERE settlement_price > 0');
            }
        }
    }

    /**
     * Reverse the migrations.
     * 回滚：将所有价格字段从"元"转换回"分"（乘以100）
     */
    public function down(): void
    {
        // 1. prices 表
        if (Schema::hasTable('prices')) {
            DB::statement('UPDATE prices SET 
                market_price = market_price * 100,
                settlement_price = settlement_price * 100,
                sale_price = sale_price * 100
                WHERE market_price > 0 OR settlement_price > 0 OR sale_price > 0');
        }

        // 2. ticket_prices 表
        if (Schema::hasTable('ticket_prices')) {
            DB::statement('UPDATE ticket_prices SET 
                sale_price = sale_price * 100,
                cost_price = cost_price * 100
                WHERE sale_price > 0 OR cost_price > 0');
        }

        // 3. pkg_product_daily_prices 表
        if (Schema::hasTable('pkg_product_daily_prices')) {
            DB::statement('UPDATE pkg_product_daily_prices SET 
                sale_price = sale_price * 100,
                cost_price = cost_price * 100
                WHERE sale_price > 0 OR cost_price > 0');
        }

        // 4. res_hotel_daily_stock 表
        if (Schema::hasTable('res_hotel_daily_stock')) {
            DB::statement('UPDATE res_hotel_daily_stock SET 
                sale_price = sale_price * 100,
                cost_price = cost_price * 100
                WHERE sale_price > 0 OR cost_price > 0');
        }

        // 5. sales_product_prices 表
        if (Schema::hasTable('sales_product_prices')) {
            DB::statement('UPDATE sales_product_prices SET 
                sale_price = sale_price * 100,
                settlement_price = settlement_price * 100
                WHERE sale_price > 0 OR settlement_price > 0');
        }

        // 6. price_rules 表
        if (Schema::hasTable('price_rules')) {
            DB::statement('UPDATE price_rules SET 
                market_price_adjustment = market_price_adjustment * 100,
                settlement_price_adjustment = settlement_price_adjustment * 100,
                sale_price_adjustment = sale_price_adjustment * 100
                WHERE market_price_adjustment != 0 OR settlement_price_adjustment != 0 OR sale_price_adjustment != 0');
        }

        // 7. orders 表
        if (Schema::hasTable('orders')) {
            DB::statement('UPDATE orders SET 
                total_amount = total_amount * 100,
                settlement_amount = settlement_amount * 100
                WHERE total_amount > 0 OR settlement_amount > 0');
        }

        // 8. order_items 表
        if (Schema::hasTable('order_items')) {
            DB::statement('UPDATE order_items SET 
                unit_price = unit_price * 100,
                total_price = total_price * 100
                WHERE unit_price > 0 OR total_price > 0');
        }

        // 9. pkg_orders 表
        if (Schema::hasTable('pkg_orders')) {
            DB::statement('UPDATE pkg_orders SET 
                total_amount = total_amount * 100,
                settlement_amount = settlement_amount * 100
                WHERE total_amount > 0 OR settlement_amount > 0');
        }

        // 10. pkg_order_items 表
        if (Schema::hasTable('pkg_order_items')) {
            DB::statement('UPDATE pkg_order_items SET 
                unit_price = unit_price * 100,
                total_price = total_price * 100
                WHERE unit_price > 0 OR total_price > 0');
        }

        // 11. system_pkg_orders 表
        if (Schema::hasTable('system_pkg_orders')) {
            DB::statement('UPDATE system_pkg_orders SET 
                total_amount = total_amount * 100,
                settlement_amount = settlement_amount * 100
                WHERE total_amount > 0 OR settlement_amount > 0');
        }

        // 12. system_pkg_order_items 表
        if (Schema::hasTable('system_pkg_order_items')) {
            DB::statement('UPDATE system_pkg_order_items SET 
                unit_price = unit_price * 100,
                total_price = total_price * 100
                WHERE unit_price > 0 OR total_price > 0');
        }

        // 13. tickets 表（默认价格字段）
        if (Schema::hasTable('tickets')) {
            if (Schema::hasColumn('tickets', 'market_price')) {
                DB::statement('UPDATE tickets SET 
                    market_price = market_price * 100
                    WHERE market_price > 0');
            }
            if (Schema::hasColumn('tickets', 'sale_price')) {
                DB::statement('UPDATE tickets SET 
                    sale_price = sale_price * 100
                    WHERE sale_price > 0');
            }
            if (Schema::hasColumn('tickets', 'settlement_price')) {
                DB::statement('UPDATE tickets SET 
                    settlement_price = settlement_price * 100
                    WHERE settlement_price > 0');
            }
        }
    }
};
