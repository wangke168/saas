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
        if (Schema::hasTable('ota_configs')) {
            return;
        }
        
        Schema::create('ota_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ota_platform_id')->constrained('ota_platforms')->cascadeOnDelete()->comment('OTA平台ID');
            $table->string('account')->comment('接口账号');
            $table->string('secret_key')->comment('接口密钥');
            $table->text('aes_key')->nullable()->comment('AES加密密钥（携程）');
            $table->text('aes_iv')->nullable()->comment('AES加密初始向量（携程）');
            $table->text('rsa_private_key')->nullable()->comment('RSA私钥（飞猪）');
            $table->text('rsa_public_key')->nullable()->comment('RSA公钥（飞猪）');
            $table->string('api_url')->nullable()->comment('API地址');
            $table->string('callback_url')->nullable()->comment('回调地址');
            $table->string('environment')->default('sandbox')->comment('环境: sandbox=沙箱, production=生产');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
            
            $table->unique('ota_platform_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ota_configs');
    }
};
