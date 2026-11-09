<?php

use App\Actions\Clinics\SaveDailySession;
use App\Enums\PaymentStatus;
use App\Enums\SessionStatus;
use App\Enums\TransactionType;
use App\Models\ClinicRole;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\DailySession;
use App\Models\LineItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\SessionLineItem;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Monday 2 November 2026, 06:00 UTC.
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(6, 0));
});

/**
 * A Monday-only daily session from today, billing 400.00 per day.
 *
 * @param  list<int>  $patientIds
 */
function mondaySession(ClinicUser $holder, array $patientIds): DailySession
{
    return app(SaveDailySession::class)->create($holder->clinic, [
        'clinic_user_id' => $holder->id,
        'name' => 'Monday rehab',
        'line_item_id' => LineItem::factory()->for($holder->clinic)->create()->id,
        'unit_price' => '400.00',
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
    ], $patientIds);
}

/**
 * A regular session member billed the given amount, in the given session.
 */
function billedMember(ClinicSession $session, string $amount, PaymentStatus $status = PaymentStatus::Pending): ClinicSessionMember
{
    $sessionMember = ClinicSessionMember::factory()->for($session)->paymentStatus($status)->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create(['quantity' => 1, 'unit_price' => $amount]);

    if ($status !== PaymentStatus::Pending) {
        $sessionMember->payment->update(['amount' => $amount]);
    }

    return $sessionMember;
}

test('payments are listed with their status, past-due flag and a summary per status', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $past = ClinicSession::factory()->create(['clinic_user_id' => $admin->id, 'starts_at' => '2026-10-30 09:00:00', 'ends_at' => '2026-10-30 10:00:00']);
    $upcoming = ClinicSession::factory()->create(['clinic_user_id' => $admin->id, 'starts_at' => '2026-11-05 09:00:00', 'ends_at' => '2026-11-05 10:00:00']);
    $cancelled = ClinicSession::factory()->status(SessionStatus::Cancelled)->create(['clinic_user_id' => $admin->id]);
    $pastDue = billedMember($past, '300.00');
    billedMember($upcoming, '200.00');
    billedMember($upcoming, '1000.00', PaymentStatus::Done);
    billedMember($past, '50.00', PaymentStatus::Refunded);
    billedMember($cancelled, '999.00');
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.payments.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.summary.outstanding', ['count' => 2, 'total' => '500.00'])
        ->assertJsonPath('meta.summary.past_due', ['count' => 1, 'total' => '300.00'])
        ->assertJsonPath('meta.summary.paid', ['count' => 1, 'total' => '1000.00'])
        ->assertJsonPath('meta.summary.refunded', ['count' => 1, 'total' => '50.00']);

    $this->getJson(route('clinic.payments.index', [$admin->clinic_id, 'status' => 'PAST_DUE']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pastDue->payment->id)
        ->assertJsonPath('data.0.is_past_due', true)
        ->assertJsonPath('data.0.amount', '300.00')
        ->assertJsonPath('data.0.status', 'PENDING');
});

test('payments of another clinic are neither listed nor shown', function () {
    $foreign = billedMember(ClinicSession::factory()->create(), '300.00');
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.payments.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.summary.outstanding.count', 0);
    $this->getJson(route('clinic.payments.show', [$admin->clinic_id, $foreign->payment]))->assertNotFound();
    $this->getJson(route('clinic.payments.index', [$admin->clinic_id, 'patient_id' => $foreign->patient_id]))->assertJsonCount(0, 'data');
});

test('a worker prepays upcoming days, including days not scheduled yet', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = mondaySession($worker, [$patient->id]);
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.payments.prepay.store', $worker->clinic_id), [
        'daily_session_id' => $dailySession->id,
        'patient_id' => $patient->id,
        // 9 Nov is scheduled already; 7 Dec is beyond the 14 days scheduled ahead.
        'dates' => ['2026-11-09', '2026-12-07'],
    ])->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'DONE')
        ->assertJsonPath('data.1.amount', '400.00');

    $paid = Payment::query()->where('payment_status_id', PaymentStatus::Done)->with('clinicSession')->get();

    expect($paid->map(fn (Payment $payment) => $payment->clinicSession->occurs_on->toDateString())->sort()->values()->all())
        ->toBe(['2026-11-09', '2026-12-07']);
    $this->assertDatabaseCount('transactions', 2);
    $this->assertDatabaseHas('transactions', ['payment_id' => $paid->first()->id, 'transaction_type_id' => TransactionType::Inbound->value, 'amount' => '400.00', 'clinic_user_id' => $worker->id]);
});

test('prepaying refuses days the session does not run on, past days and unenrolled patients', function (array $input, string $field) {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = mondaySession($worker, [$patient->id]);
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.payments.prepay.store', $worker->clinic_id), [
        'daily_session_id' => $dailySession->id,
        'patient_id' => isset($input['unenrolled']) ? Patient::factory()->for($worker->clinic)->create()->id : $patient->id,
        'dates' => $input['dates'] ?? ['2026-11-09'],
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('transactions', 0);
})->with([
    'a tuesday' => [['dates' => ['2026-11-10']], 'dates'],
    'a past monday' => [['dates' => ['2026-10-26']], 'dates'],
    'not enrolled' => [['unenrolled' => true], 'patient_id'],
]);

test('prepaying is all or nothing when one day is already paid', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create();
    $dailySession = mondaySession($worker, [$patient->id]);
    $dailySession->clinicSessions()->whereDate('occurs_on', '2026-11-16')->sole()->payments()->update(['payment_status_id' => PaymentStatus::Done]);
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.payments.prepay.store', $worker->clinic_id), [
        'daily_session_id' => $dailySession->id,
        'patient_id' => $patient->id,
        'dates' => ['2026-11-09', '2026-11-16'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['dates' => 'Mon 16 Nov already has a payment recorded.']);

    $this->assertDatabaseCount('transactions', 0);
    expect(Payment::query()->where('payment_status_id', PaymentStatus::Done)->count())->toBe(1);
});

test('prepaying a daily session or patient of another clinic is refused', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $otherWorker = ClinicUser::factory()->worker()->create();
    $foreignPatient = Patient::factory()->for($otherWorker->clinic)->create();
    $foreignSession = mondaySession($otherWorker, [$foreignPatient->id]);
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.payments.prepay.store', $worker->clinic_id), [
        'daily_session_id' => $foreignSession->id,
        'patient_id' => $foreignPatient->id,
        'dates' => ['2026-11-09'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['daily_session_id', 'patient_id']);

    $this->assertDatabaseCount('transactions', 0);
});

test('a role without the payments permissions cannot list or prepay', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.sessions.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.payments.index', $role->clinic_id))->assertForbidden();
    $this->postJson(route('clinic.payments.prepay.store', $role->clinic_id), [])->assertForbidden();
});
