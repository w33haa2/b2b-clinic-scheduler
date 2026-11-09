<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A discount takes money off a billed item. It is either clinic-wide
     * (created by a Clinic Admin, usable by everyone) or personal (created by
     * one member, visible only to them). Its target says whose cut pays for
     * it: the clinic's or the member's.
     */
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // Null for a clinic-wide discount; set for a personal one.
            $table->foreignId('clinic_user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('discount_type_id');
            $table->unsignedTinyInteger('discount_target_id');
            // A percentage (0-100) when the type is PERCENTAGE, otherwise an amount of money.
            $table->decimal('value', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('discount_type_id')->references('id')->on('discount_types');
            $table->foreign('discount_target_id')->references('id')->on('discount_targets');
            $table->index(['clinic_id', 'clinic_user_id']);
        });

        Schema::table('session_line_items', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->after('unit_price')->constrained()->nullOnDelete();
            // What the discount took off, and whose cut paid for it, when the item
            // was billed. Snapshotted like unit_price, so editing the discount
            // afterwards never rewrites past bills or past payouts.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_id');
            $table->unsignedTinyInteger('discount_target_id')->nullable()->after('discount_amount');

            $table->foreign('discount_target_id')->references('id')->on('discount_targets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_line_items', function (Blueprint $table) {
            $table->dropForeign(['discount_target_id']);
            $table->dropConstrainedForeignId('discount_id');
            $table->dropColumn(['discount_amount', 'discount_target_id']);
        });

        Schema::dropIfExists('discounts');
    }
};
