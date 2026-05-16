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
            $table->foreignId('software_provider_id')->nullable()->constrained('software_providers')->onDelete('set null')->comment('所属软件服务商ID（NULL=自控库存，有值=第三方库存）');
            $table->string('name')->comment('门票名称');
            $table->string('code')->unique()->comment('门票编码');
            $table->string('external_ticket_id', 100)->nullable()->comment('第三方门票ID（用于API对接）');
            $table->text('description')->nullable()->comment('门票描述');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            $table->softDeletes()->comment('软删除');
            
            $table->index('scenic_spot_id', 'idx_scenic_spot');
            $table->index('software_provider_id', 'idx_software_provider');
            $table->index('code', 'idx_code');
            $table->index('external_ticket_id', 'idx_external');
            $table->index('is_active', 'idx_active');
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
