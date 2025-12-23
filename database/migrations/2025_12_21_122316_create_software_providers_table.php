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
        if (Schema::hasTable('software_providers')) {
            return;
        }
        
        Schema::create('software_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('软件服务商名称');
            $table->string('code')->unique()->comment('服务商编码');
            $table->text('description')->nullable()->comment('描述');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('software_providers');
    }
};
