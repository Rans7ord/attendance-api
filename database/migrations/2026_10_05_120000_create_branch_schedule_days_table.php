<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function up(): void
    {
        Schema::create('branch_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('day'); // monday..sunday
            $table->boolean('is_working')->default(false);
            $table->time('start_time')->default('08:00:00');
            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->timestamps();
            $table->unique(['branch_id', 'day']);
        });

        // Migrate any existing single-schedule rows into the new per-day
        // shape, using the old uniform start_time/grace for every day so
        // nothing changes in effect until an admin edits a specific day.
        $old = DB::table('branch_schedules')->get();
        foreach ($old as $schedule) {
            foreach (self::DAYS as $day) {
                DB::table('branch_schedule_days')->insert([
                    'company_id' => $schedule->company_id,
                    'branch_id' => $schedule->branch_id,
                    'day' => $day,
                    'is_working' => (bool) $schedule->{$day},
                    'start_time' => $schedule->start_time,
                    'grace_minutes' => $schedule->grace_minutes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::dropIfExists('branch_schedules');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_schedule_days');
        // Note: does not recreate branch_schedules or restore its data.
    }
};