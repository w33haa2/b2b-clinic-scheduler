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
        Schema::create('clinic_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'name']);
            $table->unique(['clinic_id', 'key']);
        });

        Schema::create('clinic_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('clinic_user_role', function (Blueprint $table) {
            $table->foreignId('clinic_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_role_id')->constrained()->cascadeOnDelete();

            $table->primary(['clinic_user_id', 'clinic_role_id']);
        });

        Schema::create('clinic_role_permission', function (Blueprint $table) {
            $table->foreignId('clinic_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['clinic_role_id', 'clinic_permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_role_permission');
        Schema::dropIfExists('clinic_user_role');
        Schema::dropIfExists('clinic_permissions');
        Schema::dropIfExists('clinic_roles');
    }
};
