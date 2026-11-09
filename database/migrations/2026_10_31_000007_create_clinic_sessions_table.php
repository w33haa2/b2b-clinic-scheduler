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
        Schema::create('clinic_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_user_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('status_id')->default(1);
            $table->timestamps();

            $table->foreign('status_id')->references('id')->on('session_statuses');
            $table->index(['clinic_id', 'starts_at']);
            $table->index(['clinic_user_id', 'starts_at']);
        });

        Schema::create('clinic_session_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('payment_status_id')->default(1);
            $table->timestamps();

            $table->foreign('payment_status_id')->references('id')->on('payment_statuses');
            $table->unique(['clinic_session_id', 'patient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_session_members');
        Schema::dropIfExists('clinic_sessions');
    }
};
