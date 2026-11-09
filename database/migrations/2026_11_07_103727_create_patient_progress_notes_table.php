<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progress notes: how a patient is doing over time, written by the staff
     * who see them. Each note rates the change since the last one and can
     * carry measurements, so the clinic can tell at a glance whether someone
     * is getting better.
     */
    public function up(): void
    {
        Schema::create('patient_progress_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // The member who wrote it; kept when their membership is removed.
            $table->foreignId('clinic_user_id')->nullable()->constrained()->nullOnDelete();
            // The session it came out of, when it came out of one.
            $table->foreignId('clinic_session_id')->nullable()->constrained()->nullOnDelete();
            // The day being written about, as a date in the clinic's timezone.
            $table->date('recorded_on');
            $table->unsignedTinyInteger('progress_rating_id')->default(1);
            $table->string('summary');
            $table->text('body')->nullable();
            // What to do before the next visit.
            $table->text('plan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('progress_rating_id')->references('id')->on('progress_ratings');
            $table->index(['clinic_id', 'recorded_on']);
            $table->index(['patient_id', 'recorded_on']);
        });

        Schema::create('patient_progress_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_progress_note_id')->constrained()->cascadeOnDelete();
            // e.g. "Pain", "Knee flexion". Free text so each clinic tracks its own.
            $table->string('name', 100);
            $table->decimal('value', 10, 2);
            // e.g. "/10", "deg", "kg".
            $table->string('unit', 20)->nullable();
            $table->timestamps();

            $table->unique(['patient_progress_note_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_progress_metrics');
        Schema::dropIfExists('patient_progress_notes');
    }
};
