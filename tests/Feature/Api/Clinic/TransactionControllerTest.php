<?php

use App\Enums\TransactionType;
use App\Models\ClinicUser;
use App\Models\Transaction;
use Laravel\Sanctum\Sanctum;

test('transactions are listed for the clinic and filtered by type', function () {
    $inbound = Transaction::factory()->create();
    Transaction::factory()->create([
        'session_line_item_id' => $inbound->session_line_item_id,
        'transaction_type_id' => TransactionType::Reversed,
    ]);
    Transaction::factory()->create();
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $inbound->clinic_id])->user);

    $this->getJson(route('clinic.transactions.index', [$inbound->clinic_id, 'type' => 'INBOUND']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inbound->id)
        ->assertJsonPath('data.0.type', 'INBOUND');
});
