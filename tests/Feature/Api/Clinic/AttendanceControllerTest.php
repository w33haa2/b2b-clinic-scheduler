<?php

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use Laravel\Sanctum\Sanctum;

test('attendance is listed newest first with a count per status', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Attendance::factory()->on($admin, '2026-11-02')->create();
    Attendance::factory()->on($worker, '2026-11-02')->status(AttendanceStatus::Late)->create();
    Attendance::factory()->on($worker, '2026-11-03')->status(AttendanceStatus::Absent)->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.attendance.index', $admin->clinic_id))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.work_date', '2026-11-03')
        ->assertJsonPath('data.0.status', 'ABSENT')
        ->assertJsonPath('data.0.counts_as_worked', false)
        ->assertJsonPath('meta.summary', ['PRESENT' => 1, 'ABSENT' => 1, 'LATE' => 1, 'EXCUSED' => 0]);

    $this->getJson(route('clinic.attendance.index', [$admin->clinic_id, 'clinic_user_id' => $worker->id, 'from' => '2026-11-03']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.member_id', $worker->id);
});

test('an admin records attendance for another member, with check-in and check-out times', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.attendance.store', $admin->clinic_id), [
        'clinic_user_id' => $worker->id,
        'work_date' => '2026-11-02',
        'status' => 'LATE',
        'checked_in_at' => '2026-11-02T09:30:00+00:00',
        'checked_out_at' => '2026-11-02T17:00:00+00:00',
        'notes' => 'Traffic.',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'LATE')
        ->assertJsonPath('data.counts_as_worked', true)
        ->assertJsonPath('data.worked_minutes', 450)
        ->assertJsonPath('data.recorded_by.id', $admin->id);

    $attendance = Attendance::query()->sole();

    expect($attendance->clinic_id)->toBe($admin->clinic_id)
        ->and($attendance->clinic_user_id)->toBe($worker->id)
        ->and($attendance->work_date->toDateString())->toBe('2026-11-02')
        ->and($attendance->attendance_status_id)->toBe(AttendanceStatus::Late)
        ->and($attendance->recorded_by_clinic_user_id)->toBe($admin->id);
});

test('a member can only record and change their own attendance', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $colleague = ClinicUser::factory()->worker()->create(['clinic_id' => $worker->clinic_id]);
    $theirs = Attendance::factory()->on($colleague, '2026-11-02')->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.attendance.store', $worker->clinic_id), [
        'clinic_user_id' => $colleague->id,
        'work_date' => '2026-11-03',
    ])->assertUnprocessable()->assertJsonValidationErrors(['clinic_user_id' => 'Only a Clinic Admin can record attendance for another member.']);

    $this->patchJson(route('clinic.attendance.update', [$worker->clinic_id, $theirs]), ['status' => 'ABSENT'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clinic_user_id');

    $this->postJson(route('clinic.attendance.store', $worker->clinic_id), [
        'clinic_user_id' => $worker->id,
        'work_date' => '2026-11-03',
    ])->assertCreated();
});

test('a member only gets one attendance row per day', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Attendance::factory()->on($admin, '2026-11-02')->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.attendance.store', $admin->clinic_id), [
        'clinic_user_id' => $admin->id,
        'work_date' => '2026-11-02',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['clinic_user_id' => 'This member already has attendance recorded for that day. Edit it instead.']);
});

test('updating attendance changes the status but never the member or the day', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $other = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    $attendance = Attendance::factory()->on($admin, '2026-11-02')->create();
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.attendance.update', [$admin->clinic_id, $attendance]), [
        'status' => 'EXCUSED',
        'clinic_user_id' => $other->id,
        'work_date' => '2026-11-09',
    ])->assertOk()
        ->assertJsonPath('data.status', 'EXCUSED')
        ->assertJsonPath('data.member_id', $admin->id)
        ->assertJsonPath('data.work_date', '2026-11-02');
});

test('attendance of another clinic is neither listed nor reachable', function () {
    $foreign = Attendance::factory()->create();
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.attendance.index', $admin->clinic_id))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('clinic.attendance.show', [$admin->clinic_id, $foreign]))->assertNotFound();
    $this->deleteJson(route('clinic.attendance.destroy', [$admin->clinic_id, $foreign]))->assertNotFound();
});

test('a role without the attendance permissions cannot list or record', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.sessions.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.attendance.index', $role->clinic_id))->assertForbidden();
    $this->postJson(route('clinic.attendance.store', $role->clinic_id), [])->assertForbidden();
});
