<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "Morning", "Night"
            $table->time('start_time');
            // Display/reporting for now — the clock-in deadline still
            // works as start_time + grace_minutes, same as the branch
            // schedule. A shift crossing midnight (22:00-06:00) is not
            // specially handled yet.
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};