<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 在数据迁移完成后，将字段改为必填
     */
    public function up(): void
    {
        // 验证数据完整性
        $softwareProvidersWithoutUrl = DB::table('software_providers')
            ->whereNull('api_url')
            ->count();
        
        $productsWithoutProvider = DB::table('products')
            ->whereNull('software_provider_id')
            ->count();
        
        if ($softwareProvidersWithoutUrl > 0) {
            throw new \Exception("存在 {$softwareProvidersWithoutUrl} 个软件服务商没有配置API地址，请先配置后再执行迁移");
        }
        
        if ($productsWithoutProvider > 0) {
            throw new \Exception("存在 {$productsWithoutProvider} 个产品没有配置服务商，请先配置后再执行迁移");
        }
        
        // 将 software_providers.api_url 改为必填
        Schema::table('software_providers', function (Blueprint $table) {
            $table->string('api_url', 255)->nullable(false)->change();
        });
        
        // 将 products.software_provider_id 改为必填，并修改外键约束为 RESTRICT
        Schema::table('products', function (Blueprint $table) {
            // 先删除旧的外键约束
            $table->dropForeign('products_software_provider_id_foreign');
            
            // 修改字段为必填
            $table->unsignedBigInteger('software_provider_id')->nullable(false)->change();
            
            // 重新添加外键约束，使用 RESTRICT
            $table->foreign('software_provider_id', 'products_software_provider_id_foreign')
                  ->references('id')
                  ->on('software_providers')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 将 software_providers.api_url 改回可空
        Schema::table('software_providers', function (Blueprint $table) {
            $table->string('api_url', 255)->nullable()->change();
        });
        
        // 将 products.software_provider_id 改回可空，并修改外键约束为 SET NULL
        Schema::table('products', function (Blueprint $table) {
            // 先删除旧的外键约束
            $table->dropForeign('products_software_provider_id_foreign');
            
            // 修改字段为可空
            $table->unsignedBigInteger('software_provider_id')->nullable()->change();
            
            // 重新添加外键约束，使用 SET NULL
            $table->foreign('software_provider_id', 'products_software_provider_id_foreign')
                  ->references('id')
                  ->on('software_providers')
                  ->onDelete('set null');
        });
    }
};
