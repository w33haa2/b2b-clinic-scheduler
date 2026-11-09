<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a clinic splits the money it bills between itself and the member who
     * facilitated the session. The clinic-wide share is the default; a member
     * can be given their own cut on their membership row.
     */
    public function up(): void
    {
        Schema::create('clinic_payout_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained()->cascadeOnDelete();
            // The part of every billed item that goes to the member holding the session.
            $table->decimal('worker_share_percentage', 5, 2)->default(60);
            // Pay the member for sessions whose patient has not paid yet (tracked as a receivable).
            $table->boolean('include_pending_payments')->default(true);
            // Only pay for days the member was marked present or late.
            $table->boolean('require_attendance')->default(false);
            $table->timestamps();
        });

        Schema::table('clinic_users', function (Blueprint $table) {
            // Overrides the clinic-wide share for this member; null = use the clinic default.
            $table->decimal('payout_share_percentage', 5, 2)->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinic_users', function (Blueprint $table) {
            $table->dropColumn('payout_share_percentage');
        });

        Schema::dropIfExists('clinic_payout_settings');
    }
};
