<?php

use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment status moves from clinic_session_members.payment_status_id to
     * its own table (one payment per patient per session), and every
     * transaction points at the payment it belongs to.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_session_member_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('payment_status_id')->default(1);
            // Total of the billed items when the payment was recorded; null while unpaid.
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->foreign('payment_status_id')->references('id')->on('payment_statuses');
            $table->index(['clinic_id', 'payment_status_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('clinic_id')->constrained()->restrictOnDelete();
        });

        $this->copyPaymentStatuses();

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable(false)->change();
        });

        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->dropForeign(['payment_status_id']);
            $table->dropColumn('payment_status_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clinic_session_members', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_status_id')->default(1)->after('patient_id');
            $table->foreign('payment_status_id')->references('id')->on('payment_statuses');
        });

        DB::table('payments')->orderBy('id')->each(fn (object $payment) => DB::table('clinic_session_members')
            ->where('id', $payment->clinic_session_member_id)
            ->update(['payment_status_id' => $payment->payment_status_id]));

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });

        Schema::dropIfExists('payments');
    }

    /**
     * One payment per existing session member, with the amount and dates
     * taken from its transactions.
     */
    private function copyPaymentStatuses(): void
    {
        DB::table('clinic_session_members')
            ->join('clinic_sessions', 'clinic_sessions.id', '=', 'clinic_session_members.clinic_session_id')
            ->select('clinic_session_members.*', 'clinic_sessions.clinic_id')
            ->orderBy('clinic_session_members.id')
            ->each(function (object $member): void {
                $transactions = DB::table('transactions')
                    ->join('session_line_items', 'session_line_items.id', '=', 'transactions.session_line_item_id')
                    ->where('session_line_items.clinic_session_member_id', $member->id)
                    ->select('transactions.*')
                    ->get();
                $inbound = $transactions->filter(fn (object $transaction): bool => (int) $transaction->transaction_type_id === 1);
                $reversed = $transactions->filter(fn (object $transaction): bool => (int) $transaction->transaction_type_id === 2);

                $paymentId = DB::table('payments')->insertGetId([
                    'clinic_id' => $member->clinic_id,
                    'clinic_session_id' => $member->clinic_session_id,
                    'clinic_session_member_id' => $member->id,
                    'patient_id' => $member->patient_id,
                    'payment_status_id' => $member->payment_status_id,
                    'amount' => (int) $member->payment_status_id === 1 ? null : $this->sum($inbound->pluck('amount')->map(fn (mixed $amount): string => (string) $amount)->all()),
                    'paid_at' => $inbound->min('created_at'),
                    'refunded_at' => $reversed->min('created_at'),
                    'created_at' => $member->created_at,
                    'updated_at' => $member->updated_at,
                ]);

                DB::table('transactions')->whereIn('id', $transactions->pluck('id'))->update(['payment_id' => $paymentId]);
            });
    }

    /**
     * @param  array<string>  $amounts
     */
    private function sum(array $amounts): string
    {
        return (string) array_reduce($amounts, fn (BigDecimal $total, string $amount): BigDecimal => $total->plus($amount), BigDecimal::zero())->toScale(2);
    }
};
