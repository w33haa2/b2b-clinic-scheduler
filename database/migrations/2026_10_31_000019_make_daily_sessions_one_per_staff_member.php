<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staff member holds at most one daily session, which is kept up to
     * date (edited, ended with `ends_on`, resumed) instead of creating more.
     */
    public function up(): void
    {
        Schema::table('daily_sessions', function (Blueprint $table) {
            $table->unique('clinic_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_sessions', function (Blueprint $table) {
            // The foreign key needs an index once the unique one is gone.
            $table->index('clinic_user_id');
            $table->dropUnique(['clinic_user_id']);
        });
    }
};
