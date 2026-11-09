<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per clinic member per working day. Attendance tells a Clinic
     * Admin which days a member actually worked, so a payout can be limited
     * to those days (clinic_payout_settings.require_attendance).
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_user_id')->constrained()->cascadeOnDelete();
            // The working day, as a date in the clinic's timezone.
            $table->date('work_date');
            $table->unsignedTinyInteger('attendance_status_id')->default(1);
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->string('notes', 500)->nullable();
            // The member who recorded it, which is not always the member it is about.
            $table->foreignId('recorded_by_clinic_user_id')->nullable()->constrained('clinic_users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('attendance_status_id')->references('id')->on('attendance_statuses');
            $table->unique(['clinic_user_id', 'work_date']);
            $table->index(['clinic_id', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
