<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A patient who misses a day of a daily session can be given a makeup on
     * another date. The makeup is a session of its own, so these columns sit
     * on the day they left: where they went, when it was arranged and why.
     *
     * The row on the missed day stays. Deleting it would lose the fact that
     * the patient was expected and did not come, which is the part the clinic
     * needs to see afterwards.
     */
    public function up(): void
    {
        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->foreignId('moved_to_clinic_session_member_id')
                ->nullable()
                ->after('customised')
                ->constrained('clinic_session_members')
                ->nullOnDelete();
            $table->timestamp('moved_at')->nullable()->after('moved_to_clinic_session_member_id');
            $table->string('moved_reason')->nullable()->after('moved_at');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_to_clinic_session_member_id');
            $table->dropColumn(['moved_at', 'moved_reason']);
        });
    }
};
