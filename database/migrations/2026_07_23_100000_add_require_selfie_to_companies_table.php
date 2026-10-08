<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Defaults to true: attendance is meaningless if you can't
            // confirm who actually clocked in. Admins who genuinely can't
            // require it (no working cameras, cultural/privacy reasons,
            // etc.) can switch it off for their org.
            $table->boolean('require_selfie_on_join')->default(true)->after('join_code_uses_count');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('require_selfie_on_join');
        });
    }
};