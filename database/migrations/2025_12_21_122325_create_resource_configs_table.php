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
        if (Schema::hasTable('resource_configs')) {
            return;
        }
        
        Schema::create('resource_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_provider_id')->constrained('resource_providers')->cascadeOnDelete()->comment('资源方ID');
            $table->string('username')->comment('用户名');
            $table->string('password')->comment('密码');
            $table->string('api_url')->comment('接口地址');
            $table->string('environment')->default('test')->comment('环境: test=测试, production=生产');
            $table->json('extra_config')->nullable()->comment('额外配置（JSON）');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->unique('resource_provider_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_configs');
    }
};
