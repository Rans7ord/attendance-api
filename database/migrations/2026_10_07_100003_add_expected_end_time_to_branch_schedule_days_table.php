<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_schedule_days', function (Blueprint $table) {
            // Optional — only needed for the half-day rule (late AND left
            // early). Null means no expected end time is set, so half-day
            // detection simply doesn't apply to that day yet.
            $table->time('expected_end_time')->nullable()->after('grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('branch_schedule_days', function (Blueprint $table) {
            $table->dropColumn('expected_end_time');
        });
    }
};