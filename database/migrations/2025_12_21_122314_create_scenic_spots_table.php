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
        if (Schema::hasTable('scenic_spots')) {
            return;
        }
        
        Schema::create('scenic_spots', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('景区名称');
            $table->string('code')->unique()->comment('景区编码');
            $table->text('description')->nullable()->comment('景区描述');
            $table->string('address')->nullable()->comment('景区地址');
            $table->string('contact_phone')->nullable()->comment('联系电话');
            $table->unsignedBigInteger('software_provider_id')->nullable()->comment('软件服务商ID');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenic_spots');
    }
};
