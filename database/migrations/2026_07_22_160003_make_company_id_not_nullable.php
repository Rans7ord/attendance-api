<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'members', 'branches', 'attendance', 'attendance_attempts'] as $table) {
            Schema::table($table, function (Blueprint $tableBlueprint) {
                $tableBlueprint->foreignId('company_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'members', 'branches', 'attendance', 'attendance_attempts'] as $table) {
            Schema::table($table, function (Blueprint $tableBlueprint) {
                $tableBlueprint->foreignId('company_id')->nullable()->change();
            });
        }
    }
};
