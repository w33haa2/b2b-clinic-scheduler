<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a clinic owes one member for the sessions they facilitated in a
     * period. payout_items is the detail: one row per billed item, with the
     * split snapshotted. session_line_item_id is unique, so an item can only
     * ever be paid out once (the same lock invoice_items uses).
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_user_id')->constrained()->restrictOnDelete();
            // Counts up per clinic, shown as PAY-000001.
            $table->unsignedInteger('number');
            // The period covered, as dates in the clinic's timezone.
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('payout_status_id')->default(1);
            // The share used when it was generated, so later config changes do not move it.
            $table->decimal('share_percentage', 5, 2);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('worker_amount', 12, 2)->default(0);
            $table->decimal('clinic_amount', 12, 2)->default(0);
            // The part of worker_amount paid in advance of the patient paying.
            $table->decimal('pending_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('generated_by_clinic_user_id')->nullable()->constrained('clinic_users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('payout_status_id')->references('id')->on('payout_statuses');
            $table->unique(['clinic_id', 'number']);
            $table->index(['clinic_id', 'clinic_user_id', 'period_start']);
        });

        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_line_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('share_percentage', 5, 2);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('worker_amount', 12, 2);
            $table->decimal('clinic_amount', 12, 2);
            // The patient had not paid when the payout was generated: the member
            // was paid in advance and the clinic is still owed the money.
            $table->boolean('was_pending')->default(false);
            // When that payment was finally recorded. Cleared again by a refund.
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['payout_id', 'was_pending']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
    }
};
