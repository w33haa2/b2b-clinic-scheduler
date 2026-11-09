<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            // Null for platform-level events (sign-ins, super admins, user accounts).
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            // Who did it; null for the system (queue, console) or unknown sign-in attempts.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->nullableMorphs('subject');
            // A name for the subject at the time (e.g. the patient's name), kept if it is later deleted.
            $table->string('subject_label')->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['clinic_id', 'created_at']);
            $table->index('created_at');
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
