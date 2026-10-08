<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // null = never expires / unlimited, both default to "open" so
            // existing companies aren't suddenly locked out.
            $table->timestamp('join_code_expires_at')->nullable()->after('join_code');
            $table->unsignedInteger('join_code_max_uses')->nullable()->after('join_code_expires_at');
            $table->unsignedInteger('join_code_uses_count')->default(0)->after('join_code_max_uses');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['join_code_expires_at', 'join_code_max_uses', 'join_code_uses_count']);
        });
    }
};
