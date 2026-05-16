<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 添加索引以优化景区识别查询性能
     */
    public function up(): void
    {
        // hotels 表索引
        if (Schema::hasTable('hotels')) {
            Schema::table('hotels', function (Blueprint $table) {
                // external_code 索引（如果字段存在且索引不存在）
                if (Schema::hasColumn('hotels', 'external_code') && !$this->hasIndex('hotels', 'hotels_external_code_index')) {
                    $table->index('external_code', 'hotels_external_code_index');
                }
                // code 索引（如果不存在）
                if (Schema::hasColumn('hotels', 'code') && !$this->hasIndex('hotels', 'hotels_code_index')) {
                    $table->index('code', 'hotels_code_index');
                }
            });
        }

        // orders 表索引
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                // resource_order_no 索引（软件服务商的订单号，如果不存在）
                if (Schema::hasColumn('orders', 'resource_order_no') && !$this->hasIndex('orders', 'orders_resource_order_no_index')) {
                    $table->index('resource_order_no', 'orders_resource_order_no_index');
                }
                // ota_order_no 索引（如果不存在）
                if (Schema::hasColumn('orders', 'ota_order_no') && !$this->hasIndex('orders', 'orders_ota_order_no_index')) {
                    $table->index('ota_order_no', 'orders_ota_order_no_index');
                }
            });
        }

        // products 表索引
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                // external_code 索引（如果字段存在且索引不存在）
                if (Schema::hasColumn('products', 'external_code') && !$this->hasIndex('products', 'products_external_code_index')) {
                    $table->index('external_code', 'products_external_code_index');
                }
                // code 索引（如果不存在，注意 code 字段已经有 unique 约束，可能不需要额外索引）
                // 但为了查询性能，如果不存在索引则添加
                if (!$this->hasIndex('products', 'products_code_index')) {
                    $table->index('code', 'products_code_index');
                }
            });
        }

        // resource_configs 表索引
        if (Schema::hasTable('resource_configs')) {
            Schema::table('resource_configs', function (Blueprint $table) {
                // software_provider_id + username 复合索引（用于认证参数匹配）
                // 只有当两个字段都存在时才创建索引
                if (Schema::hasColumn('resource_configs', 'software_provider_id') 
                    && Schema::hasColumn('resource_configs', 'username')
                    && !$this->hasIndex('resource_configs', 'resource_configs_software_username_index')) {
                    $table->index(['software_provider_id', 'username'], 'resource_configs_software_username_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('hotels')) {
            Schema::table('hotels', function (Blueprint $table) {
                $table->dropIndex('hotels_external_code_index');
                $table->dropIndex('hotels_code_index');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if ($this->hasIndex('orders', 'orders_resource_order_no_index')) {
                    $table->dropIndex('orders_resource_order_no_index');
                }
                if ($this->hasIndex('orders', 'orders_ota_order_no_index')) {
                    $table->dropIndex('orders_ota_order_no_index');
                }
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if ($this->hasIndex('products', 'products_external_code_index')) {
                    $table->dropIndex('products_external_code_index');
                }
                if ($this->hasIndex('products', 'products_code_index')) {
                    $table->dropIndex('products_code_index');
                }
            });
        }

        if (Schema::hasTable('resource_configs')) {
            Schema::table('resource_configs', function (Blueprint $table) {
                $table->dropIndex('resource_configs_software_username_index');
            });
        }
    }

    /**
     * 检查索引是否存在
     */
    protected function hasIndex(string $table, string $indexName): bool
    {
        try {
            $connection = Schema::getConnection();
            $databaseName = $connection->getDatabaseName();
            
            $result = $connection->select(
                "SELECT COUNT(*) as count FROM information_schema.statistics 
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$databaseName, $table, $indexName]
            );
            
            return $result[0]->count > 0;
        } catch (\Exception $e) {
            // 如果查询失败，假设索引不存在
            return false;
        }
    }
};

