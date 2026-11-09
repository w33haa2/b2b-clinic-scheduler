<?php

use App\Actions\Patients\CreateDefaultPatientForm;
use App\Models\Clinic;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The clinic's patient form, one row per input.
     *
     * A field with a patient_column reads and writes that column on
     * `patients`. Those are the built-in fields: they can be relabelled,
     * reordered and made optional, but never deleted, and the column they
     * point at never changes. Everything else is a custom field whose
     * answers live in patient_field_values.
     */
    public function up(): void
    {
        Schema::create('patient_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // Stable machine name, used by exports and the public form.
            $table->string('key', 100);
            $table->string('label');
            $table->string('placeholder')->nullable();
            $table->string('help_text', 500)->nullable();
            $table->unsignedTinyInteger('patient_form_field_type_id');
            // Choices for SELECT, MULTISELECT and RADIO: a JSON list of strings.
            $table->json('options')->nullable();
            // Extra rules the builder offers: max_length, min, max.
            $table->json('validation')->nullable();
            $table->boolean('is_required')->default(false);
            // A heading the field is grouped under on the form.
            $table->string('section')->default('Patient details');
            $table->unsignedSmallInteger('position')->default(0);
            // The patients column this field is: set for built-in fields only.
            $table->string('patient_column', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('patient_form_field_type_id')->references('id')->on('patient_form_field_types');
            $table->unique(['clinic_id', 'key']);
            $table->index(['clinic_id', 'position']);
        });

        // Every clinic keeps the form it already had: the nine columns of
        // `patients`, in the order the old dialog showed them.
        Clinic::withTrashed()->orderBy('id')->each(
            fn (Clinic $clinic) => app(CreateDefaultPatientForm::class)->handle($clinic),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_form_fields');
    }
};
