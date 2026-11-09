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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_line_item_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('transaction_type_id');
            $table->decimal('amount', 12, 2);
            $table->foreignId('clinic_user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->foreign('transaction_type_id')->references('id')->on('transaction_types');
            $table->index(['clinic_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
