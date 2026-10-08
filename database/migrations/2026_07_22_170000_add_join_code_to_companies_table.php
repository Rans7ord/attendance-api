<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('join_code', 8)->nullable()->unique()->after('name');
        });

        // Backfill a join code for any company that already exists (e.g. the
        // "Default Company" row from the earlier multi-tenant backfill).
        DB::table('companies')->whereNull('join_code')->get()->each(function ($company) {
            DB::table('companies')->where('id', $company->id)->update([
                'join_code' => $this->generateUniqueCode(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('join_code');
        });
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (DB::table('companies')->where('join_code', $code)->exists());

        return $code;
    }
};
