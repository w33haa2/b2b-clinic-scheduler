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
        Schema::create('daily_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // The service billed to every patient for every day, at unit_price.
            $table->foreignId('line_item_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            // The daily window, in the clinic's timezone.
            $table->time('start_time');
            $table->time('end_time');
            // ISO weekdays the session repeats on: 1 = Monday … 7 = Sunday.
            $table->json('weekdays');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'starts_on']);
        });

        Schema::create('daily_session_patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['daily_session_id', 'patient_id']);
        });

        // Each day of a daily session is a clinic session of its own.
        Schema::table('clinic_sessions', function (Blueprint $table) {
            $table->foreignId('daily_session_id')->nullable()->after('clinic_user_id')->constrained()->restrictOnDelete();
            $table->date('occurs_on')->nullable()->after('daily_session_id');

            $table->unique(['daily_session_id', 'occurs_on']);
        });

        // A patient's plotted time slot inside a daily session's day (UTC).
        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->after('patient_id');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });

        Schema::table('clinic_sessions', function (Blueprint $table) {
            $table->dropUnique(['daily_session_id', 'occurs_on']);
            $table->dropConstrainedForeignId('daily_session_id');
            $table->dropColumn('occurs_on');
        });

        Schema::dropIfExists('daily_session_patients');
        Schema::dropIfExists('daily_sessions');
    }
};
