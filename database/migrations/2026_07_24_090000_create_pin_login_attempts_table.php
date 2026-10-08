<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_login_attempts', function (Blueprint $table) {
            $table->id();
            // Nullable: an attempt with an email that matches no account
            // still gets logged (useful for spotting brute-force patterns)
            // but obviously has no user to attach to.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_attempted');
            $table->string('photo_path')->nullable();
            $table->boolean('success');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_login_attempts');
    }
};