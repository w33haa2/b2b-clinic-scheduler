<?php

use App\Enums\PaymentStatus;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Monday 16 November 2026, 12:00 UTC, like the payout tests.
    $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
});

/**
 * A clinic with one payout covering one paid and one unpaid item, which is
 * the situation the receivables page exists for.
 *
 * @return array{admin: ClinicUser, worker: ClinicUser, unpaid: SessionLineItem}
 */
function clinicWithAnAdvance(): array
{
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    payableItem($worker, '1000.00', PaymentStatus::Done);
    $unpaid = payableItem($worker, '500.00');
    Sanctum::actingAs($admin->user);

    test()->postJson(route('clinic.payouts.store', $admin->clinic_id), payoutPeriod($worker))->assertCreated();

    return ['admin' => $admin, 'worker' => $worker, 'unpaid' => $unpaid];
}

test('only the money paid out ahead of a patient payment shows up, with totals', function () {
    ['admin' => $admin, 'unpaid' => $unpaid] = clinicWithAnAdvance();

    $this->getJson(route('clinic.receivables.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.session_line_item_id', $unpaid->id)
        ->assertJsonPath('data.0.worker_amount', '300.00')
        ->assertJsonPath('data.0.is_unsettled', true)
        ->assertJsonPath('data.0.payment_status', 'PENDING')
        ->assertJsonPath('meta.summary.outstanding', ['count' => 1, 'total' => '300.00'])
        ->assertJsonPath('meta.summary.settled', ['count' => 0, 'total' => '0.00'])
        ->assertJsonPath('meta.summary.advanced', ['count' => 1, 'total' => '300.00']);
});

test('a row settles itself when the patient pays and moves to the settled tab', function () {
    ['admin' => $admin, 'unpaid' => $unpaid] = clinicWithAnAdvance();
    $sessionMember = $unpaid->sessionMember;

    $this->putJson(
        route('clinic.sessions.members.payment.update', [$admin->clinic_id, $sessionMember->clinic_session_id, $sessionMember->id]),
        ['payment_status' => 'DONE'],
    )->assertOk();

    $this->getJson(route('clinic.receivables.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.summary.outstanding', ['count' => 0, 'total' => '0.00'])
        ->assertJsonPath('meta.summary.settled', ['count' => 1, 'total' => '300.00']);

    $this->getJson(route('clinic.receivables.index', [$admin->clinic_id, 'state' => 'SETTLED']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_unsettled', false);
});

test('outstanding money is broken down per staff member', function () {
    ['admin' => $admin, 'worker' => $worker] = clinicWithAnAdvance();

    $this->getJson(route('clinic.receivables.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonPath('meta.by_member.0.clinic_user_id', $worker->id)
        ->assertJsonPath('meta.by_member.0.count', 1)
        ->assertJsonPath('meta.by_member.0.total', '300.00');

    $this->getJson(route('clinic.receivables.index', [$admin->clinic_id, 'clinic_user_id' => $admin->id]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('receivables of another clinic never show up', function () {
    ['admin' => $otherAdmin] = clinicWithAnAdvance();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    expect($otherAdmin->clinic_id)->not->toBe($admin->clinic_id);

    $this->getJson(route('clinic.receivables.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.summary.outstanding.count', 0);
});

test('receivables are Clinic Admin only, whatever a role grants', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.payouts.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.receivables.index', $role->clinic_id))->assertForbidden();
});
