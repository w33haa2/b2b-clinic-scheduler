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
        // Saved schedule filters; each belongs to one member and only they see it.
        Schema::create('calendar_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('rules');
            $table->timestamps();

            $table->index(['clinic_user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_filters');
    }
};
