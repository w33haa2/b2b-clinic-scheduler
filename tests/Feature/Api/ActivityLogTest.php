<?php

use App\Enums\PaymentStatus;
use App\Models\ActivityLog;
use App\Models\ClinicPermission;
use App\Models\ClinicSession;
use App\Models\ClinicSessionMember;
use App\Models\ClinicUser;
use App\Models\LineItem;
use App\Models\Patient;
use App\Models\SessionLineItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('changes made through the api are logged against the clinic with the actor', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.patients.store', $worker->clinic_id), ['first_name' => 'Ana', 'last_name' => 'Reyes'])->assertCreated();

    $log = ActivityLog::query()->where('action', 'patient.created')->sole();

    expect($log->clinic_id)->toBe($worker->clinic_id)
        ->and($log->user_id)->toBe($worker->user_id)
        ->and($log->subject_label)->toBe('Ana Reyes');
});

test('patient updates record which personal fields changed but not their values', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $patient = Patient::factory()->for($worker->clinic)->create(['phone' => '111', 'last_name' => 'Reyes']);
    Sanctum::actingAs($worker->user);

    $this->patchJson(route('clinic.patients.update', [$worker->clinic_id, $patient]), ['phone' => '222', 'last_name' => 'Cruz'])->assertOk();

    $changes = ActivityLog::query()->where('action', 'patient.updated')->sole()->changes;

    expect($changes['phone'])->toBe(['old' => null, 'new' => null])
        ->and($changes['last_name'])->toBe(['old' => 'Reyes', 'new' => 'Cruz']);
});

test('status changes and payments get their own readable entries', function () {
    $sessionMember = ClinicSessionMember::factory()->create();
    SessionLineItem::factory()->for($sessionMember, 'sessionMember')->create();
    $session = $sessionMember->clinicSession;
    $admin = ClinicUser::factory()->admin()->create(['clinic_id' => $session->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.sessions.status.update', [$session->clinic_id, $session]), ['status' => 'ONGOING'])->assertOk();
    $this->putJson(route('clinic.sessions.members.payment.update', [$session->clinic_id, $session, $sessionMember]), ['payment_status' => 'DONE'])->assertOk();

    $this->getJson(route('clinic.activity.index', [$session->clinic_id, 'category' => 'billing']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'payment.recorded')
        ->assertJsonPath('data.0.changes.payment_status_id', ['old' => 'Unpaid', 'new' => 'Paid']);

    $this->getJson(route('clinic.activity.index', [$session->clinic_id, 'category' => 'schedule']))
        ->assertJsonPath('data.0.action', 'session.status_changed')
        ->assertJsonPath('data.0.description', 'Changed status of session '.$session->fresh()->activityLabel().' from Scheduled to In progress');
});

test('role assignments and role permission changes are logged', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $workerRole = $worker->clinicRoles()->sole();
    $adminRole = $admin->clinicRoles()->sole();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.members.update', [$admin->clinic_id, $worker]), ['role_ids' => [$adminRole->id]])->assertOk();
    $this->putJson(route('clinic.roles.update', [$admin->clinic_id, $workerRole]), [
        'permission_ids' => [ClinicPermission::query()->where('name', 'clinic.invoices.index')->value('id')],
    ])->assertOk();

    expect(ActivityLog::query()->where('action', 'member.roles_changed')->sole()->changes['roles'])
        ->toBe(['old' => ['Clinic Worker'], 'new' => ['Clinic Admin']])
        ->and(ActivityLog::query()->where('action', 'role.permissions_changed')->sole()->changes['permissions']['new'])
        ->toBe(['clinic.invoices.index']);
});

test('sign-ins and failed sign-ins are logged at platform level', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(ActivityLog::query()->where('action', 'auth.login_failed')->sole())
        ->subject_label->toBe($user->email)
        ->user_id->toBeNull()
        ->and(ActivityLog::query()->where('action', 'auth.login')->sole())
        ->user_id->toBe($user->id)
        ->clinic_id->toBeNull();
});

test('the clinic log shows only its own clinic and can be filtered by actor', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Patient::factory()->create();
    ActivityLog::query()->delete();

    Sanctum::actingAs($worker->user);
    $this->postJson(route('clinic.patients.store', $admin->clinic_id), ['first_name' => 'Ana', 'last_name' => 'Reyes']);
    Sanctum::actingAs($admin->user);
    $this->postJson(route('clinic.patients.store', $admin->clinic_id), ['first_name' => 'Jo', 'last_name' => 'Kim']);
    Patient::factory()->create();

    $this->getJson(route('clinic.activity.index', [$admin->clinic_id, 'user_id' => $worker->user_id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject_label', 'Ana Reyes')
        ->assertJsonPath('data.0.actor.id', $worker->user_id)
        ->assertJsonCount(2, 'meta.filters.actors');
});

test('the default worker role cannot read the clinic log', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.activity.index', $worker->clinic_id))->assertForbidden();
});

test('super admins filter the platform log by level and clinic', function () {
    $clinicPatient = Patient::factory()->create();
    Patient::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($superAdmin);

    $this->getJson(route('internal.activity.index', ['level' => 'platform']))
        ->assertOk()
        ->assertJsonPath('data.0.action', 'user.created')
        ->assertJsonPath('data.0.clinic', null);

    $this->getJson(route('internal.activity.index', ['clinic_id' => $clinicPatient->clinic_id, 'category' => 'patients']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.clinic.id', $clinicPatient->clinic_id);
});

test('clinic members cannot read the platform log', function () {
    Sanctum::actingAs(ClinicUser::factory()->admin()->create()->user);

    $this->getJson(route('internal.activity.index'))->assertForbidden();
});

test('entries older than the retention period are pruned', function () {
    config(['activity.retention_days' => 30]);
    $old = ActivityLog::query()->create(['action' => 'auth.login']);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $recent = ActivityLog::query()->create(['action' => 'auth.login']);

    $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

    $this->assertModelMissing($old);
    $this->assertModelExists($recent);
});

test('archiving a session member removal and re-adding a member are logged with the right verbs', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $session = ClinicSession::factory()->create(['clinic_user_id' => $worker->id]);
    $sessionMember = ClinicSessionMember::factory()->for($session)->paymentStatus(PaymentStatus::Pending)->create();
    Sanctum::actingAs($admin->user);

    $this->deleteJson(route('clinic.sessions.members.destroy', [$session->clinic_id, $session, $sessionMember]))->assertNoContent();
    $this->deleteJson(route('clinic.members.destroy', [$admin->clinic_id, $worker]))->assertNoContent();

    expect(ActivityLog::query()->where('action', 'session_patient.removed')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'member.removed')->sole()->subject_label)->toBe($worker->user->name);
});

test('daily sessions, enrolments, plotting and prepayments are logged under schedule and billing', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(6, 0));
    $admin = ClinicUser::factory()->admin()->create();
    $patient = Patient::factory()->for($admin->clinic)->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    Sanctum::actingAs($admin->user);

    $dailySessionId = $this->postJson(route('clinic.daily-sessions.store', $admin->clinic_id), [
        'name' => 'Daily physio',
        'clinic_user_id' => $admin->id,
        'line_item_id' => LineItem::factory()->for($admin->clinic)->create()->id,
        'start_time' => '08:00',
        'end_time' => '12:00',
        'weekdays' => [1],
        'starts_on' => '2026-11-02',
        'patient_ids' => [$patient->id],
    ])->assertCreated()->json('data.id');
    $day = ClinicSession::query()->where('daily_session_id', $dailySessionId)->orderBy('starts_at')->firstOrFail();
    $this->putJson(route('clinic.sessions.members.slot.update', [$admin->clinic_id, $day, $day->sessionMembers()->sole()]), [
        'starts_at' => '2026-11-02T08:00:00Z',
        'ends_at' => '2026-11-02T08:30:00Z',
    ])->assertOk();
    $this->postJson(route('clinic.payments.prepay.store', $admin->clinic_id), [
        'daily_session_id' => $dailySessionId,
        'patient_id' => $patient->id,
        'dates' => ['2026-11-09'],
    ])->assertCreated();

    $this->getJson(route('clinic.activity.index', [$admin->clinic_id, 'category' => 'schedule']))
        ->assertJsonPath('data.0.description', 'Plotted Ana Reyes into a time slot of session Daily physio on Mon 2 Nov, 08:00')
        ->assertJsonPath('data.1.description', 'Enrolled Ana Reyes in daily session Daily physio')
        ->assertJsonPath('data.2.description', 'Created daily session Daily physio');
    $this->getJson(route('clinic.activity.index', [$admin->clinic_id, 'category' => 'billing']))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Recorded prepayment from Ana Reyes for session Daily physio on Mon 9 Nov, 08:00')
        ->assertJsonPath('data.0.changes.payment_status_id', ['old' => 'Unpaid', 'new' => 'Paid']);
});
