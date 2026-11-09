<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each enrolled patient gets a plan: the service they are billed every
     * day and their usual time slot, which is plotted automatically. A day
     * changed by hand for a patient is marked `customised`, so later plan
     * changes leave that day alone.
     */
    public function up(): void
    {
        Schema::table('daily_session_patients', function (Blueprint $table) {
            $table->foreignId('line_item_id')->nullable()->after('patient_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_price', 12, 2)->nullable()->after('line_item_id');
            // Usual slot, in the clinic's timezone; null when the patient has none.
            $table->time('start_time')->nullable()->after('unit_price');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('start_time');
        });

        DB::table('daily_session_patients')
            ->join('daily_sessions', 'daily_sessions.id', '=', 'daily_session_patients.daily_session_id')
            ->select('daily_session_patients.id', 'daily_sessions.line_item_id', 'daily_sessions.unit_price')
            ->orderBy('daily_session_patients.id')
            ->each(fn (object $row) => DB::table('daily_session_patients')
                ->where('id', $row->id)
                ->update(['line_item_id' => $row->line_item_id, 'unit_price' => $row->unit_price]));

        Schema::table('daily_session_patients', function (Blueprint $table) {
            $table->unsignedBigInteger('line_item_id')->nullable(false)->change();
            $table->decimal('unit_price', 12, 2)->nullable(false)->change();
        });

        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->boolean('customised')->default(false)->after('ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->dropColumn('customised');
        });

        Schema::table('daily_session_patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('line_item_id');
            $table->dropColumn(['unit_price', 'start_time', 'duration_minutes']);
        });
    }
};
