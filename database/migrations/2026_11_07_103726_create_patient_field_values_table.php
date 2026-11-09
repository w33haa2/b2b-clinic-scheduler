<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One patient's answer to one custom field. Built-in fields are not
     * stored here: they write straight to their patients column, which is
     * what keeps search, scheduling and invoices working.
     */
    public function up(): void
    {
        Schema::create('patient_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // Restricted, not cascaded: archiving a field must keep its answers,
            // so they come back if the field is restored.
            $table->foreignId('patient_form_field_id')->constrained()->restrictOnDelete();
            // A MULTISELECT answer is stored as a JSON array; everything else as text.
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'patient_form_field_id']);
            $table->index('patient_form_field_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_field_values');
    }
};
