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
        if (Schema::hasTable('ota_platforms')) {
            return;
        }
        
        Schema::create('ota_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('平台名称');
            $table->string('code')->unique()->comment('平台代码: ctrip=携程, fliggy=飞猪, meituan=美团');
            $table->text('description')->nullable()->comment('平台描述');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ota_platforms');
    }
};
