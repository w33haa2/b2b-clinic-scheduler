<?php

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Models\ClinicRole;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\Invoice;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('marking a payment done records one inbound transaction per billed item', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create(['quantity' => 2, 'unit_price' => '1500.00']);
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create(['quantity' => 1, 'unit_price' => '800.00']);
    $session = $sessionMember->clinicSession;
    $admin = ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'DONE');

    $this->assertDatabaseCount('transactions', 2);
    $this->assertDatabaseHas('transactions', ['clinic_id' => $session->clinic_id, 'transaction_type_id' => TransactionType::Inbound->value, 'amount' => '3000.00', 'clinic_user_id' => $admin->id]);
    $this->assertDatabaseHas('transactions', ['transaction_type_id' => TransactionType::Inbound->value, 'amount' => '800.00']);
});

test('refunding a payment records one reversed transaction per billed item', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create(['quantity' => 1, 'unit_price' => '800.00']);
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'REFUNDED'])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'REFUNDED');

    $this->assertDatabaseHas('transactions', ['transaction_type_id' => TransactionType::Reversed->value, 'amount' => '800.00']);
});

test('a payment cannot skip or reverse its order', function (PaymentStatus $from, string $to) {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus($from)->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => $to])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_status');

    $this->assertDatabaseCount('transactions', 0);
})->with([
    'pending to refunded' => [PaymentStatus::Pending, 'REFUNDED'],
    'done to done' => [PaymentStatus::Done, 'DONE'],
    'refunded to done' => [PaymentStatus::Refunded, 'DONE'],
]);

test('a patient with no billed items cannot be marked paid', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_status' => 'This patient has no billed items in the session.']);
});

test('a cancelled session cannot be paid', function () {
    $session = ClinicSession::factory()->status(SessionStatus::Cancelled)->create();
    $sessionMember = ClinicSessionMember::factory()->for($session)->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_status' => 'A cancelled session cannot be paid.']);
});

test('the default worker role can record payments', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])
        ->assertOk();
});

test('a role without the permission cannot record payments', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    $role = ClinicRole::factory()->create(['clinic_id' => $session->clinic_id]);
    Sanctum::actingAs(ClinicUser::factory()->withRole($role)->create()->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])
        ->assertForbidden();
});

test('paying stores the total on the payment and links every transaction to it', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create(['quantity' => 3, 'unit_price' => '100.10']);
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])
        ->assertOk();

    $payment = $sessionMember->payment()->sole();

    expect($payment->payment_status_id)->toBe(PaymentStatus::Done)
        ->and($payment->amount)->toBe('300.30')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->transactions()->count())->toBe(1);
});

test('an invoiced payment cannot be refunded until its items leave the invoice', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    $item = SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    $session = $sessionMember->clinicSession;
    $invoice = Invoice::factory()->create(['clinic_id' => $session->clinic_id, 'patient_id' => $sessionMember->patient_id]);
    $invoice->sessionLineItems()->attach($item);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'REFUNDED'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_status' => "These items are on invoice {$invoice->reference()}. Remove them from the invoice before refunding."]);

    $this->assertDatabaseCount('transactions', 0);
});

test('a session with a paid patient cannot be cancelled until the payment is refunded', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->putJson(route('clinic.sessions.status.update', [$session->clinic_id, $session]), ['status' => 'CANCELLED'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'Patients have paid for this session. Refund their payments before cancelling it.']);
});
