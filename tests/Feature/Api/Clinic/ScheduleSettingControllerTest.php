<?php

use App\Models\ClinicSession;
use App\Models\ClinicUser;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
 * Opening hours decide what the calendar offers and what may be booked. The
 * rules worth holding down are the boundaries: the clinic is closed at the
 * weekend until it says otherwise, a window has to end after it starts, and
 * a clinic that needs the freedom can switch enforcement off entirely.
 */

/** A Tuesday and a Saturday, well clear of today. */
const A_TUESDAY = '2030-01-01';

const A_SATURDAY = '2030-01-05';

test('a clinic starts closed at the weekend, working weekdays', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.schedule-settings.show', $admin->clinic_id))
        ->assertOk()
        ->assertJsonPath('data.weekday_opens_at', '08:00')
        ->assertJsonPath('data.weekday_closes_at', '17:00')
        ->assertJsonPath('data.saturday_enabled', false)
        ->assertJsonPath('data.sunday_enabled', false)
        ->assertJsonPath('data.allow_outside_hours', false)
        ->assertJsonPath('data.open_weekdays', [1, 2, 3, 4, 5]);
});

test('an admin opens on Saturday without opening on Sunday', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.schedule-settings.update', $admin->clinic_id), [
        'saturday_enabled' => true,
        'weekend_opens_at' => '09:00',
        'weekend_closes_at' => '13:00',
    ])->assertOk()
        ->assertJsonPath('data.saturday_enabled', true)
        ->assertJsonPath('data.sunday_enabled', false)
        ->assertJsonPath('data.open_weekdays', [1, 2, 3, 4, 5, 6]);
});

test('the widest window spans the weekday and weekend hours together', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    // The calendar grid has to show 07:00 to 18:00 to fit both.
    $this->putJson(route('clinic.schedule-settings.update', $admin->clinic_id), [
        'weekday_opens_at' => '09:00',
        'weekday_closes_at' => '18:00',
        'weekend_opens_at' => '07:00',
        'weekend_closes_at' => '12:00',
        'saturday_enabled' => true,
    ])->assertOk()
        ->assertJsonPath('data.opens_at', '07:00')
        ->assertJsonPath('data.closes_at', '18:00');
});

test('hours must end after they start, even when only one side is sent', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.schedule-settings.update', $admin->clinic_id), [
        'weekday_opens_at' => '18:00',
        'weekday_closes_at' => '09:00',
    ])->assertJsonValidationErrors('weekday_closes_at');

    // Compared against the saved 08:00 opening, not skipped for being absent.
    $this->putJson(route('clinic.schedule-settings.update', $admin->clinic_id), [
        'weekday_closes_at' => '07:00',
    ])->assertJsonValidationErrors('weekday_closes_at');
});

test('a worker can neither read nor change the hours', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->getJson(route('clinic.schedule-settings.show', $worker->clinic_id))->assertForbidden();
    $this->putJson(route('clinic.schedule-settings.update', $worker->clinic_id), ['saturday_enabled' => true])
        ->assertForbidden();
});

test('a session outside the opening hours is refused', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.sessions.store', $worker->clinic_id), [
        'starts_at' => A_TUESDAY.'T06:00:00Z',
        'ends_at' => A_TUESDAY.'T07:00:00Z',
        'clinic_user_id' => $worker->id,
    ])->assertJsonValidationErrors('starts_at');
});

test('a session on a day the clinic does not open is refused', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    // Inside the weekday hours, but it is a Saturday and Saturday is off.
    $this->postJson(route('clinic.sessions.store', $worker->clinic_id), [
        'starts_at' => A_SATURDAY.'T09:00:00Z',
        'ends_at' => A_SATURDAY.'T10:00:00Z',
        'clinic_user_id' => $worker->id,
    ])->assertJsonValidationErrors('starts_at');
});

test('opening on Saturday lets a Saturday session through', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $admin->clinic->scheduleSettings()->update(['saturday_enabled' => true, 'weekend_opens_at' => '09:00:00', 'weekend_closes_at' => '12:00:00']);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.sessions.store', $admin->clinic_id), [
        'starts_at' => A_SATURDAY.'T09:00:00Z',
        'ends_at' => A_SATURDAY.'T10:00:00Z',
        'clinic_user_id' => $admin->id,
    ])->assertCreated();
});

test('allowing overruns lets anything through, at any hour', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $admin->clinic->scheduleSettings()->update(['allow_outside_hours' => true]);
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.sessions.store', $admin->clinic_id), [
        'starts_at' => A_SATURDAY.'T22:00:00Z',
        'ends_at' => A_SATURDAY.'T23:00:00Z',
        'clinic_user_id' => $admin->id,
    ])->assertCreated();
});

test('a session already outside the hours can still be renamed and cancelled', function () {
    $admin = ClinicUser::factory()->admin()->create();
    // Booked while the clinic allowed it; the hours tightened afterwards.
    $session = ClinicSession::factory()->for($admin->clinic)->create([
        'clinic_user_id' => $admin->id,
        'starts_at' => A_TUESDAY.'T05:00:00Z',
        'ends_at' => A_TUESDAY.'T06:00:00Z',
    ]);
    Sanctum::actingAs($admin->user);

    $this->patchJson(route('clinic.sessions.update', [$admin->clinic_id, $session]), ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    // Moving it, though, has to land inside them.
    $this->patchJson(route('clinic.sessions.update', [$admin->clinic_id, $session]), [
        'starts_at' => A_TUESDAY.'T04:00:00Z',
        'ends_at' => A_TUESDAY.'T05:00:00Z',
    ])->assertJsonValidationErrors('starts_at');
});

test('a daily session cannot run on a weekday the clinic is closed', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $patient = Patient::factory()->for($admin->clinic)->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.daily-sessions.store', $admin->clinic_id), [
        'name' => 'Morning rehab',
        'clinic_user_id' => $admin->id,
        'line_item_id' => $admin->clinic->lineItems()->create(['item_code' => 'REHAB', 'name' => 'Rehab', 'price' => '100.00'])->id,
        'start_time' => '09:00',
        'end_time' => '11:00',
        'weekdays' => [1, 6],
        'starts_on' => A_TUESDAY,
        'patient_ids' => [$patient->id],
    ])->assertJsonValidationErrors('weekdays');
});

test('a daily window wider than the opening hours is refused', function () {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->postJson(route('clinic.daily-sessions.store', $admin->clinic_id), [
        'name' => 'Long day',
        'clinic_user_id' => $admin->id,
        'line_item_id' => $admin->clinic->lineItems()->create(['item_code' => 'REHAB', 'name' => 'Rehab', 'price' => '100.00'])->id,
        'start_time' => '07:00',
        'end_time' => '19:00',
        'weekdays' => [1, 2, 3],
        'starts_on' => A_TUESDAY,
    ])->assertJsonValidationErrors('start_time');
});
