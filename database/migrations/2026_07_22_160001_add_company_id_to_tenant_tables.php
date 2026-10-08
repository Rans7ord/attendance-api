<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * company_id is added nullable first on purpose — the next migration
     * (backfill_default_company) fills it in for existing rows, then a
     * follow-up migration can tighten it to NOT NULL once you're confident
     * every row has a value. Doing it in three small steps avoids ever
     * having a migration fail because existing rows have no company yet.
     */
    public function up(): void
    {
        $tables = ['users', 'members', 'branches', 'attendance', 'attendance_attempts'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($table) {
                if (!Schema::hasColumn($table, 'company_id')) {
                    $tableBlueprint->foreignId('company_id')
                        ->nullable()
                        ->after('id')
                        ->constrained()
                        ->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        $tables = ['users', 'members', 'branches', 'attendance', 'attendance_attempts'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($table) {
                if (Schema::hasColumn($table, 'company_id')) {
                    $tableBlueprint->dropConstrainedForeignId('company_id');
                }
            });
        }
    }
};
