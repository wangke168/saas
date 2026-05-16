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
        if (Schema::hasTable('resource_providers')) {
            return;
        }
        
        Schema::create('resource_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('资源方名称');
            $table->string('code')->unique()->comment('资源方编码');
            $table->text('description')->nullable()->comment('描述');
            $table->string('api_type')->comment('接口类型: hengdian=横店影视城');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_providers');
    }
};
