<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->comment('渠道标识，如 wuzhen');
            $table->string('source', 64)->comment('来源标识，如 wuzhen_ota_portal');
            $table->string('request_id', 64)->comment('请求幂等ID');
            $table->string('payload_hash', 64)->comment('请求体哈希');
            $table->string('status', 20)->default('received')->comment('received|processing|processed|failed');
            $table->json('result_summary')->nullable()->comment('处理摘要');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'request_id']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_requests');
    }
};
