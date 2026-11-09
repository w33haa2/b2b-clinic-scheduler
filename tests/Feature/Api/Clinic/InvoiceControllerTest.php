<?php

use App\Enums\PaymentStatus;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\Invoice;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('an invoice is created for a patient with a per-clinic number and total', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    $items = SessionLineItem::factory()->count(2)->for($sessionMember, 'sessionMember')->create(['quantity' => 1, 'unit_price' => '500.00']);
    $clinicId = $sessionMember->clinicSession->clinic_id;
    Invoice::factory()->create(['number' => 7]);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $clinicId])->user);

    $this->postJson(route('clinic.invoices.store', $clinicId), [
        'patient_id' => $sessionMember->patient_id,
        'session_line_item_ids' => $items->pluck('id')->all(),
    ])->assertCreated()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.reference', 'INV-000001')
        ->assertJsonPath('data.total', '1000.00')
        ->assertJsonCount(2, 'data.items');
});

test('the next invoice in a clinic gets the next number', function () {
    $sessionLineItem = SessionLineItem::factory()->for(ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done), 'sessionMember')->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $clinicId = $sessionMember->clinicSession->clinic_id;
    Invoice::factory()->create(['patient_id' => $sessionMember->patient_id, 'number' => 41]);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $clinicId])->user);

    $this->postJson(route('clinic.invoices.store', $clinicId), [
        'patient_id' => $sessionMember->patient_id,
        'session_line_item_ids' => [$sessionLineItem->id],
    ])->assertCreated()->assertJsonPath('data.number', 42);
});

test('an invoice rejects items billed to another patient, unpaid or already invoiced', function () {
    $sessionLineItem = SessionLineItem::factory()->for(ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done), 'sessionMember')->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $clinicId = $sessionMember->clinicSession->clinic_id;
    $otherPatientsItem = SessionLineItem::factory()->for(
        ClinicSessionMember::factory()->for($sessionMember->clinicSession)->paymentStatus(PaymentStatus::Done)->create(),
        'sessionMember',
    )->create();
    $unpaidItem = SessionLineItem::factory()->for(
        ClinicSessionMember::factory()->state(['patient_id' => $sessionMember->patient_id])->create(['clinic_session_id' => ClinicSession::factory()->create(['clinic_user_id' => $sessionMember->clinicSession->clinic_user_id])]),
        'sessionMember',
    )->create();
    Invoice::factory()->create(['patient_id' => $sessionMember->patient_id, 'number' => 1])->sessionLineItems()->attach($sessionLineItem);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $clinicId])->user);

    $this->postJson(route('clinic.invoices.store', $clinicId), [
        'patient_id' => $sessionMember->patient_id,
        'session_line_item_ids' => [$sessionLineItem->id, $otherPatientsItem->id, $unpaidItem->id],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'session_line_item_ids' => "Items {$sessionLineItem->id}, {$otherPatientsItem->id}, {$unpaidItem->id} do not belong to this patient, are not paid, or are already on another invoice.",
    ]);
});

test('updating an invoice replaces its items', function () {
    $sessionMember = ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done)->create();
    [$first, $second] = SessionLineItem::factory()->count(2)->for($sessionMember, 'sessionMember')->create();
    $clinicId = $sessionMember->clinicSession->clinic_id;
    $invoice = Invoice::factory()->create(['patient_id' => $sessionMember->patient_id]);
    $invoice->sessionLineItems()->attach($first);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $clinicId])->user);

    $this->patchJson(route('clinic.invoices.update', [$clinicId, $invoice]), ['session_line_item_ids' => [$second->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $second->id);
});

test('deleting an invoice makes its items available to invoice again', function () {
    $sessionLineItem = SessionLineItem::factory()->for(ClinicSessionMember::factory()->paymentStatus(PaymentStatus::Done), 'sessionMember')->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $clinicId = $sessionMember->clinicSession->clinic_id;
    $invoice = Invoice::factory()->create(['patient_id' => $sessionMember->patient_id]);
    $invoice->sessionLineItems()->attach($sessionLineItem);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $clinicId])->user);

    $this->getJson(route('clinic.patients.uninvoiced-items.index', [$clinicId, $sessionMember->patient_id]))->assertJsonCount(0, 'data');

    $this->deleteJson(route('clinic.invoices.destroy', [$clinicId, $invoice]))->assertNoContent();

    $this->getJson(route('clinic.patients.uninvoiced-items.index', [$clinicId, $sessionMember->patient_id]))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $sessionLineItem->id);
});
