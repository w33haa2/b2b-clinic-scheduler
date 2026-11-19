<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a clinic is open, which is what the calendar and the time pickers
     * offer. One row per clinic, created on demand with the defaults.
     *
     * Weekdays share one window and the weekend shares another, because a
     * clinic that opens on a Saturday rarely keeps its weekday hours. The two
     * weekend days are switched on independently: Saturday without Sunday is
     * the common case, and the reverse happens too.
     */
    public function up(): void
    {
        Schema::create('clinic_schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('weekday_opens_at')->default('08:00:00');
            $table->time('weekday_closes_at')->default('17:00:00');
            $table->time('weekend_opens_at')->default('09:00:00');
            $table->time('weekend_closes_at')->default('12:00:00');
            $table->boolean('saturday_enabled')->default(false);
            $table->boolean('sunday_enabled')->default(false);
            // Off by default: the hours are worth little if anything may be
            // booked outside them. Clinics that need the freedom turn it on.
            $table->boolean('allow_outside_hours')->default(false);
            $table->timestamps();
        });

        /*
         * Clinics that already exist were booking at any hour, and some of
         * them legitimately work outside 08:00-17:00. Enforcing a window
         * nobody chose would start rejecting their bookings on deploy, so
         * they are given the hours switched off. New clinics enforce.
         */
        $now = now();

        $rows = DB::table('clinics')->pluck('id')->map(fn (int $clinicId): array => [
            'clinic_id' => $clinicId,
            'allow_outside_hours' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('clinic_schedule_settings')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_schedule_settings');
    }
};
