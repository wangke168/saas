<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('cover_image', 500)->nullable()->after('contact_phone')->comment('封面图 storage 相对路径');
            $table->json('images')->nullable()->after('cover_image')->comment('相册图路径列表');
            $table->text('introduction')->nullable()->after('images')->comment('酒店介绍');
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->string('cover_image', 500)->nullable()->after('description')->comment('封面图');
            $table->json('images')->nullable()->after('cover_image')->comment('相册图路径列表');
            $table->string('bed_type', 50)->nullable()->after('images')->comment('床型');
            $table->decimal('room_area', 8, 2)->nullable()->after('bed_type')->comment('面积㎡');
            $table->string('breakfast', 100)->nullable()->after('room_area')->comment('早餐说明');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['cover_image', 'images', 'introduction']);
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['cover_image', 'images', 'bed_type', 'room_area', 'breakfast']);
        });
    }
};
