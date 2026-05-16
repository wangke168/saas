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
        if (Schema::hasTable('tickets')) {
            return;
        }
        
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->onDelete('restrict')->comment('所属景区ID');
            $table->foreignId('software_provider_id')->constrained('software_providers')->onDelete('restrict')->comment('所属服务商ID');
            $table->string('code', 6)->unique()->comment('产品编码（六位数）');
            $table->string('name')->comment('产品名称');
            $table->string('external_code')->nullable()->comment('外部编码');
            $table->decimal('market_price', 10, 2)->default(0)->comment('门市价（默认价格）');
            $table->decimal('sale_price', 10, 2)->default(0)->comment('销售价（默认价格）');
            $table->decimal('settlement_price', 10, 2)->default(0)->comment('结算价（默认价格）');
            $table->date('sale_start_date')->comment('销售开始时间');
            $table->date('sale_end_date')->comment('销售结束时间');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('scenic_spot_id');
            $table->index('software_provider_id');
            $table->index('is_active');
            $table->index('sale_start_date');
            $table->index('sale_end_date');
            $table->index('external_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};




