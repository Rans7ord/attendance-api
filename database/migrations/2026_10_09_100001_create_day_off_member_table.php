<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_off_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_off_id')->constrained('day_offs')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unique(['day_off_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_off_member');
    }
};