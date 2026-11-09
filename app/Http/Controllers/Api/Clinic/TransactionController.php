<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Clinic;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /**
     * List transactions, newest first.
     *
     * Transactions are written only when a payment is recorded or refunded
     * (including prepayments) and never change. `payout_id` says whether the
     * item behind the transaction has been paid out to staff yet.
     */
    public function index(Request $request, Clinic $clinic): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(TransactionType::codes())],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = $clinic->transactions()
            ->with(['sessionLineItem.lineItem', 'sessionLineItem.payoutItem', 'clinicUser.user', 'payment.patient'])
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('transaction_type_id', TransactionType::fromCode($type)))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', now()->parse($from)->utc()))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<', now()->parse($to)->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return TransactionResource::collection($transactions);
    }

    /**
     * Get a transaction.
     */
    public function show(Clinic $clinic, Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['sessionLineItem.lineItem', 'sessionLineItem.payoutItem', 'clinicUser.user', 'payment.patient']));
    }
}
