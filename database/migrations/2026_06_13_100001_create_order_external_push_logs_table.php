<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_external_push_logs')) {
            return;
        }

        Schema::create('order_external_push_logs', function (Blueprint $table) {
            $table->id();
            $table->string('order_type', 32)->comment('order|pkg_order');
            $table->unsignedBigInteger('order_id');
            $table->string('order_no', 64)->nullable();
            $table->foreignId('scenic_spot_id')->nullable()->constrained('scenic_spots')->nullOnDelete();
            $table->string('push_type', 32)->comment('create|status_update');
            $table->unsignedTinyInteger('route_order_status')->nullable()->comment('10/20/30/50');
            $table->string('endpoint', 128);
            $table->json('request_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response_body')->nullable();
            $table->string('status', 32)->comment('pending|success|failed');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['order_type', 'order_id']);
            $table->index(['scenic_spot_id', 'status']);
            $table->index(['order_type', 'order_id', 'push_type', 'route_order_status'], 'order_external_push_idempotency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_external_push_logs');
    }
};
