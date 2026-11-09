<?php

use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\LineItem;
use Laravel\Sanctum\Sanctum;

test('an admin adds an item to the catalog', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.line-items.store', $admin->clinic_id), [
        'item_code' => 'CONSULT',
        'name' => 'Consultation',
        'price' => 800,
    ])->assertCreated()->assertJsonPath('data.price', '800.00');
});

test('item codes are unique within a clinic but not across clinics', function () {
    $admin = ClinicUser::factory()->admin()->create();
    LineItem::factory()->for($admin->clinic)->create(['item_code' => 'CONSULT']);
    LineItem::factory()->create(['item_code' => 'OTHER']);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.line-items.store', $admin->clinic_id), ['item_code' => 'CONSULT', 'name' => 'Duplicate', 'price' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('item_code');

    $this->postJson(route('clinic.line-items.store', $admin->clinic_id), ['item_code' => 'OTHER', 'name' => 'Other', 'price' => 1])
        ->assertCreated();
});

test('an archived catalog item can no longer be billed', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    $session = $sessionMember->clinicSession;
    $lineItem = LineItem::factory()->for($session->clinic)->create();
    Sanctum::actingAs(ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id])->user);

    $this->deleteJson(route('clinic.line-items.destroy', [$session->clinic_id, $lineItem]))->assertNoContent();

    $this->assertSoftDeleted($lineItem);
    $this->postJson(route('clinic.sessions.members.line-items.store', [$session->clinic_id, $session, $sessionMember]), ['line_item_id' => $lineItem->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('line_item_id');
});
