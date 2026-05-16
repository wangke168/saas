<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 将旧的 weekday 和 date_range 类型规则转换为新的 combined 类型
     */
    public function up(): void
    {
        // 将 weekday 类型的规则转换为 combined 类型
        // weekday 规则：weekdays 有值，start_date/end_date 为 null（表示全时段）
        DB::table('price_rules')
            ->where('type', 'weekday')
            ->update(['type' => 'combined']);
        
        // 将 date_range 类型的规则转换为 combined 类型
        // date_range 规则：start_date/end_date 有值，weekdays 为 null（表示范围内所有日期）
        DB::table('price_rules')
            ->where('type', 'date_range')
            ->update(['type' => 'combined']);
    }

    /**
     * Reverse the migrations.
     * 注意：回滚时无法完全恢复原始类型，因为无法区分哪些是 weekday，哪些是 date_range
     * 这里只是将 combined 类型恢复为可能的类型
     */
    public function down(): void
    {
        // 根据字段值推断原始类型
        // 如果只有 weekdays 有值，恢复为 weekday
        DB::table('price_rules')
            ->where('type', 'combined')
            ->whereNotNull('weekdays')
            ->whereNull('start_date')
            ->whereNull('end_date')
            ->update(['type' => 'weekday']);
        
        // 如果只有 start_date/end_date 有值，恢复为 date_range
        DB::table('price_rules')
            ->where('type', 'combined')
            ->whereNull('weekdays')
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->update(['type' => 'date_range']);
    }
};
