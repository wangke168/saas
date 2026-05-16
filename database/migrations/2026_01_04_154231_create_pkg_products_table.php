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
        if (Schema::hasTable('pkg_products')) {
            return;
        }
        
        Schema::create('pkg_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenic_spot_id')->constrained('scenic_spots')->onDelete('restrict')->comment('所属景区ID');
            $table->string('product_code', 50)->unique()->comment('产品编码（内部使用）');
            $table->string('product_name', 100)->comment('产品名称');
            $table->integer('stay_days')->default(1)->comment('入住天数');
            $table->text('description')->nullable()->comment('产品描述');
            $table->integer('status')->default(1)->comment('1=上架, 0=下架');
            $table->timestamps();
            $table->softDeletes()->comment('软删除');
            
            $table->index('scenic_spot_id', 'idx_scenic_spot');
            $table->index('product_code', 'idx_code');
            $table->index('status', 'idx_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pkg_products');
    }
};
