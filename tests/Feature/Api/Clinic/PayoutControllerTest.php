<?php

use App\Enums\DiscountTarget;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\ClinicRole;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\Discount;
use App\Models\Payout;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Monday 16 November 2026, 12:00 UTC. Everything below sits in the first half of the month.
    $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
});

/**
 * One finished session held by the member, with one item billed to one
 * patient. This is the unit a payout is built from.
 */
function payableItem(
    ClinicUser $holder,
    string $amount,
    PaymentStatus $status = PaymentStatus::Pending,
    string $date = '2026-11-10',
    ?Discount $discount = null,
): SessionLineItem {
    $session = ClinicSession::factory()->status(SessionStatus::Done)->create([
        'clinic_user_id' => $holder->id,
        'clinic_id' => $holder->clinic_id,
        'starts_at' => "{$date} 09:00:00",
        'ends_at' => "{$date} 10:00:00",
    ]);

    $sessionMember = ClinicSessionMember::factory()->for($session)->paymentStatus($status)->create();

    $sessionLineItem = SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create([
        'quantity' => 1,
        'unit_price' => $amount,
        'discount_id' => $discount?->id,
        'discount_amount' => $discount?->deductionFrom($amount) ?? '0.00',
        'discount_target_id' => $discount?->discount_target_id,
    ]);

    if ($status !== PaymentStatus::Pending) {
        $sessionMember->payment->update(['amount' => $sessionLineItem->amount()]);
    }

    return $sessionLineItem;
}

/**
 * The body both the preview and the generate endpoints take.
 *
 * @return array<string, mixed>
 */
function payoutPeriod(ClinicUser $member, string $from = '2026-11-01', string $to = '2026-11-15'): array
{
    return ['clinic_user_id' => $member->id, 'period_start' => $from, 'period_end' => $to];
}

test('the preview splits every item by the clinic share without saving anything', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    payableItem($worker, '500.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertOk()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.share_percentage', '60.00')
        ->assertJsonPath('data.uses_member_override', false)
        ->assertJsonPath('data.totals.gross_amount', '1500.00')
        ->assertJsonPath('data.totals.worker_amount', '900.00')
        ->assertJsonPath('data.totals.clinic_amount', '600.00')
        ->assertJsonPath('data.totals.pending_amount', '0.00');

    $this->assertDatabaseCount('payouts', 0);
});

test('a member own rate overrides the clinic default', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id, 'payout_share_percentage' => '75.00']);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertOk()
        ->assertJsonPath('data.share_percentage', '75.00')
        ->assertJsonPath('data.uses_member_override', true)
        ->assertJsonPath('data.totals.worker_amount', '750.00')
        ->assertJsonPath('data.totals.clinic_amount', '250.00');
});

test('a discount comes out of the side it targets', function (DiscountTarget $target, string $worker, string $clinic) {
    $admin = ClinicUser::factory()->admin()->create();
    $member = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $discount = Discount::factory()->for($admin->clinic)->target($target)->create(['value' => '10.00']);
    payableItem($member, '1000.00', PaymentStatus::Done, discount: $discount);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($member))
        ->assertOk()
        ->assertJsonPath('data.totals.discount_total', '100.00')
        ->assertJsonPath('data.totals.worker_amount', $worker)
        ->assertJsonPath('data.totals.clinic_amount', $clinic);
})->with([
    'the clinic absorbs it' => [DiscountTarget::Clinic, '600.00', '300.00'],
    'the worker absorbs it' => [DiscountTarget::Worker, '500.00', '400.00'],
]);

test('generating a payout locks its items and records what was advanced against unpaid patients', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $paid = payableItem($worker, '1000.00', PaymentStatus::Done);
    $unpaid = payableItem($worker, '500.00');
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), [...payoutPeriod($worker), 'notes' => 'First half of November'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PAY-000001')
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.worker_amount', '900.00')
        ->assertJsonPath('data.clinic_amount', '600.00')
        ->assertJsonPath('data.pending_amount', '300.00')
        ->assertJsonCount(2, 'data.items');

    $this->assertDatabaseHas('payout_items', ['session_line_item_id' => $paid->id, 'worker_amount' => '600.00', 'was_pending' => false]);
    $this->assertDatabaseHas('payout_items', ['session_line_item_id' => $unpaid->id, 'worker_amount' => '300.00', 'was_pending' => true, 'settled_at' => null]);

    // The same items cannot be claimed twice.
    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period');
});

test('recording the patient payment settles the advance, and refunding opens it again', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $unpaid = payableItem($worker, '500.00');
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->assertCreated();
    $this->assertDatabaseHas('payout_items', ['session_line_item_id' => $unpaid->id, 'settled_at' => null]);

    $sessionMember = $unpaid->sessionMember;
    $paymentRoute = fn (string $status) => $this->putJson(
        route('clinic.sessions.members.payment.update', [$admin->clinic_id, $sessionMember->clinic_session_id, $sessionMember->id]),
        ['payment_status' => $status],
    );

    $paymentRoute('DONE')->assertOk();
    expect($unpaid->payoutItem()->first()->settled_at)->not->toBeNull();

    $paymentRoute('REFUNDED')->assertOk();
    expect($unpaid->payoutItem()->first()->settled_at)->toBeNull();
});

test('items are left out when they are refunded, outside the period, cancelled, or not yet over', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    payableItem($worker, '111.00', PaymentStatus::Refunded);
    payableItem($worker, '222.00', PaymentStatus::Done, date: '2026-10-20');
    payableItem($worker, '333.00', PaymentStatus::Done, date: '2026-11-25');
    $cancelled = payableItem($worker, '444.00', PaymentStatus::Done);
    $cancelled->sessionMember->clinicSession->update(['status_id' => SessionStatus::Cancelled]);
    payableItem(ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]), '555.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertOk()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.totals.gross_amount', '1000.00')
        ->assertJsonPath('data.skipped.refunded', 1);
});

test('a clinic can leave unpaid work out of payouts', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $admin->clinic->payoutSettings()->update(['include_pending_payments' => false]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    payableItem($worker, '500.00');
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertOk()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.totals.worker_amount', '600.00')
        ->assertJsonPath('data.totals.pending_amount', '0.00')
        ->assertJsonPath('data.skipped.unpaid', 1);
});

test('when the clinic requires attendance, only days marked worked are paid', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $admin->clinic->payoutSettings()->update(['require_attendance' => true]);
    payableItem($worker, '1000.00', PaymentStatus::Done, date: '2026-11-10');
    payableItem($worker, '400.00', PaymentStatus::Done, date: '2026-11-12');
    Attendance::factory()->on($worker, '2026-11-10')->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertOk()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.totals.worker_amount', '600.00')
        ->assertJsonPath('data.skipped.not_worked', 1)
        ->assertJsonPath('data.attendance.worked', 1);
});

test('items can be left out of a run by hand', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    $skipped = payableItem($worker, '500.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), [
        ...payoutPeriod($worker),
        'exclude_session_line_item_ids' => [$skipped->id],
    ])->assertCreated()
        ->assertJsonPath('data.worker_amount', '600.00')
        ->assertJsonCount(1, 'data.items');

    $this->assertDatabaseMissing('payout_items', ['session_line_item_id' => $skipped->id]);
});

test('marking a payout paid stamps it, and cancelling releases its items', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);

    $payoutId = $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->json('data.id');

    $this->putJson(route('clinic.payouts.status.update', [$admin->clinic_id, $payoutId]), ['status' => 'PAID'])
        ->assertOk()
        ->assertJsonPath('data.status', 'PAID');
    expect(Payout::query()->find($payoutId)->paid_at)->not->toBeNull();

    $this->putJson(route('clinic.payouts.status.update', [$admin->clinic_id, $payoutId]), ['status' => 'CANCELLED'])
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED');
    $this->assertDatabaseCount('payout_items', 0);

    // Released, so the work can go into a new run.
    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))
        ->assertCreated()
        ->assertJsonPath('data.reference', 'PAY-000002');
});

test('a cancelled payout cannot change again and a paid one cannot be deleted', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);

    $payoutId = $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->json('data.id');
    $this->putJson(route('clinic.payouts.status.update', [$admin->clinic_id, $payoutId]), ['status' => 'PAID'])->assertOk();

    $this->deleteJson(route('clinic.payouts.destroy', [$admin->clinic_id, $payoutId]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => 'Only a draft payout can be deleted. Cancel this one instead.']);

    $this->putJson(route('clinic.payouts.status.update', [$admin->clinic_id, $payoutId]), ['status' => 'CANCELLED'])->assertOk();
    $this->putJson(route('clinic.payouts.status.update', [$admin->clinic_id, $payoutId]), ['status' => 'PAID'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('a billed item on a payout is locked against edits and deletion', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $item = payableItem($worker, '1000.00');
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->assertCreated();

    $sessionMember = $item->sessionMember;
    $route = route('clinic.sessions.members.line-items.update', [$admin->clinic_id, $sessionMember->clinic_session_id, $sessionMember->id, $item->id]);

    $this->patchJson($route, ['quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('session_line_item');
    $this->deleteJson($route)->assertUnprocessable()->assertJsonValidationErrors('session_line_item');
});

test('a worker only ever sees their own payouts', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    payableItem($colleague, '800.00', PaymentStatus::Done);
    Sanctum::actingAs($admin->user);
    $mine = $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->json('data.id');
    $theirs = $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($colleague))->json('data.id');

    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.payouts.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine);
    $this->getJson(route('clinic.payouts.show', [$admin->clinic_id, $mine]))->assertOk();
    $this->getJson(route('clinic.payouts.show', [$admin->clinic_id, $theirs]))->assertNotFound();
});

test('the list summarises each status and the money still not collected', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    payableItem($worker, '500.00');
    Sanctum::actingAs($admin->user);
    $payoutId = $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->json('data.id');

    $this->getJson(route('clinic.payouts.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonPath('meta.summary.draft', ['count' => 1, 'total' => '900.00'])
        ->assertJsonPath('meta.summary.paid', ['count' => 0, 'total' => '0.00'])
        ->assertJsonPath('meta.summary.unsettled', ['count' => 1, 'total' => '300.00']);

    $this->putJson(route('clinic.payouts.status.update', [$admin->clinic_id, $payoutId]), ['status' => 'PAID'])->assertOk();

    $this->getJson(route('clinic.payouts.index', [$admin->clinic_id, 'status' => 'PAID']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.summary.draft.count', 0);
});

test('payouts of another clinic are out of reach', function () {
    $foreign = Payout::factory()->create();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.payouts.index', $admin->clinic_id))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('clinic.payouts.show', [$admin->clinic_id, $foreign]))->assertNotFound();
    $this->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($foreign->clinicUser))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clinic_user_id');
});

test('a period longer than a year is refused', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.payouts.preview.store', $admin->clinic_id), payoutPeriod($admin, '2026-01-01', '2027-06-01'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period_end');
});

test('a role without the payout permissions cannot list or generate', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.sessions.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.payouts.index', $role->clinic_id))->assertForbidden();
    $this->postJson(route('clinic.payouts.store', $role->clinic_id), [])->assertForbidden();
    $this->postJson(route('clinic.payouts.preview.store', $role->clinic_id), [])->assertForbidden();
});

test('payout status is driven by PayoutStatus transitions', function () {
    expect(PayoutStatus::Draft->canTransitionTo(PayoutStatus::Paid))->toBeTrue()
        ->and(PayoutStatus::Draft->canTransitionTo(PayoutStatus::Cancelled))->toBeTrue()
        ->and(PayoutStatus::Paid->canTransitionTo(PayoutStatus::Cancelled))->toBeTrue()
        ->and(PayoutStatus::Paid->canTransitionTo(PayoutStatus::Draft))->toBeFalse()
        ->and(PayoutStatus::Cancelled->canTransitionTo(PayoutStatus::Paid))->toBeFalse();
});
