<?php

use App\Models\ActivityLog;
use App\Models\ClinicPermission;
use App\Models\ClinicUser;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

test('an invoice downloads as a pdf named after its reference', function () {
    $sessionLineItem = SessionLineItem::factory()->create();
    $sessionMember = $sessionLineItem->sessionMember;
    $clinicId = $sessionMember->clinicSession->clinic_id;
    $invoice = Invoice::factory()->create(['patient_id' => $sessionMember->patient_id, 'number' => 7]);
    $invoice->sessionLineItems()->attach($sessionLineItem);
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $clinicId])->user);

    $response = $this->get(route('clinic.invoices.pdf', [$clinicId, $invoice]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="INV-000007.pdf"');

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and(ActivityLog::query()->where('action', 'invoice.downloaded')->sole()->clinic_id)->toBe($clinicId);
});

test('downloading a pdf needs the permission to view invoices', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $invoice = Invoice::factory()->create(['patient_id' => Patient::factory()->for($worker->clinic)]);
    Sanctum::actingAs($worker->user);

    $this->get(route('clinic.invoices.pdf', [$worker->clinic_id, $invoice]))->assertForbidden();
});

test('the pdf download is not a separate permission', function () {
    expect(ClinicPermission::query()->where('name', 'clinic.invoices.pdf')->exists())->toBeFalse();
});
