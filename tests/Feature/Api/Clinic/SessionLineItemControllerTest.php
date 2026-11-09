<?php

use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('billing an item copies the catalog price onto the bill', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    $lineItem = LineItem::factory()->for($session->clinic)->create(['price' => '1500.00']);
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.members.line-items.store', [$session->clinic_id, $session, $sessionMember]), [
        'line_item_id' => $lineItem->id,
        'quantity' => 2,
    ])->assertCreated()
        ->assertJsonPath('data.unit_price', '1500.00')
        ->assertJsonPath('data.amount', '3000.00');

    $lineItem->update(['price' => '2000.00']);

    $this->getJson(route('clinic.sessions.members.line-items.index', [$session->clinic_id, $session, $sessionMember]))
        ->assertJsonPath('data.0.unit_price', '1500.00');
});

test('an item from another clinic catalog cannot be billed', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.members.line-items.store', [$session->clinic_id, $session, $sessionMember]), [
        'line_item_id' => LineItem::factory()->create()->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('line_item_id');
});

test('items cannot be billed on a cancelled session', function () {
    $session = ClinicSession::factory()->status(SessionStatus::Cancelled)->create();
    $sessionMember = ClinicSessionMember::factory()->for($session)->create();
    Sanctum::actingAs($session->clinicUser->user);

    $this->postJson(route('clinic.sessions.members.line-items.store', [$session->clinic_id, $session, $sessionMember]), [
        'line_item_id' => LineItem::factory()->for($session->clinic)->create()->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('session');
});

test('billed items are locked once the patient has paid', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    $sessionLineItem = SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->patchJson(route('clinic.sessions.members.line-items.update', [$session->clinic_id, $session, $sessionMember, $sessionLineItem]), ['quantity' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_status' => 'Billed items are locked once the payment is DONE.']);
});

test('an invoiced item cannot be removed', function () {
    $sessionLineItem = SessionLineItem::factory()->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $session = $sessionMember->clinicSession;
    Invoice::factory()->create(['patient_id' => $sessionMember->patient_id])->sessionLineItems()->attach($sessionLineItem);
    Sanctum::actingAs($session->clinicUser->user);

    $this->deleteJson(route('clinic.sessions.members.line-items.destroy', [$session->clinic_id, $session, $sessionMember, $sessionLineItem]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session_line_item');
});

test('an unpaid, uninvoiced item is removed', function () {
    $sessionLineItem = SessionLineItem::factory()->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $session = $sessionMember->clinicSession;
    Sanctum::actingAs($session->clinicUser->user);

    $this->deleteJson(route('clinic.sessions.members.line-items.destroy', [$session->clinic_id, $session, $sessionMember, $sessionLineItem]))
        ->assertNoContent();

    $this->assertModelMissing($sessionLineItem);
});
