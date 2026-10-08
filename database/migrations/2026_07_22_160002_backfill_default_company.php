<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every row created before multi-tenancy existed belongs to nobody.
     * Rather than lose that data or hand-edit the DB on the live server,
     * we create one real company ("Logonvoice Limited" / your first
     * tenant) and attach all pre-existing rows to it. New companies
     * created after this point go through the normal signup/admin flow.
     */
    public function up(): void
    {
        $defaultCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Default Company',
            'industry' => 'Corporate',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['users', 'members', 'branches', 'attendance', 'attendance_attempts'] as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);
        }
    }

    public function down(): void
    {
        // Intentionally left blank — this is a one-way data backfill.
        // Reversing it would mean guessing which rows to null out again.
    }
};
