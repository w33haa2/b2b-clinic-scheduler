<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A link that lets one patient fill the clinic's form in themselves, with
     * no account. Like clinic_invitations, only a SHA-256 hash of the token
     * is stored and the link works once.
     */
    public function up(): void
    {
        Schema::create('patient_form_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // Set to update an existing patient; null to take on a new one.
            $table->foreignId('patient_id')->nullable()->constrained()->cascadeOnDelete();
            // Who it was sent to, for the staff list. Not used to authenticate.
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('token_hash')->unique();
            $table->foreignId('created_by_clinic_user_id')->nullable()->constrained('clinic_users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_form_invitations');
    }
};
