<?php

use App\Models\Attendance;
use App\Models\Clinic;
use App\Models\ClinicSession;
use App\Models\ClinicUser;
use App\Models\Discount;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Patient;
use App\Models\PatientFormInvitation;
use App\Models\PatientProgressNote;
use App\Models\Payout;
use App\Models\SessionLineItem;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * Every list that backs a table in the app is paginated and honours ?per_page.
 * Each route here has at least two rows, so per_page=1 must give two pages.
 */

test('clinic list endpoints are paginated and honour per_page', function (string $route) {
    $admin = ClinicUser::factory()->admin()->create();
    ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Patient::factory()->count(2)->for($admin->clinic)->create();
    LineItem::factory()->count(2)->for($admin->clinic)->create();
    ClinicSession::factory()->count(2)->create(['clinic_user_id' => $admin->id]);
    $items = SessionLineItem::factory()->count(2)->create();
    $items->each(fn ($item) => Transaction::factory()->create(['session_line_item_id' => $item->id, 'clinic_id' => $admin->clinic_id]));
    Invoice::factory()->count(2)->create(['patient_id' => Patient::query()->where('clinic_id', $admin->clinic_id)->value('id')]);
    Attendance::factory()->on($admin, '2026-11-02')->create();
    Attendance::factory()->on($admin, '2026-11-03')->create();
    Discount::factory()->count(2)->for($admin->clinic)->create();
    Payout::factory()->count(2)->create(['clinic_user_id' => $admin->id]);
    PatientFormInvitation::factory()->count(2)->for($admin->clinic)->create();
    Patient::query()->where('clinic_id', $admin->clinic_id)->get()
        ->each(fn (Patient $patient) => PatientProgressNote::factory()->on($patient, '2026-11-02')->by($admin)->create());
    Sanctum::actingAs($admin->user);

    $this->getJson(route($route, [$admin->clinic_id, 'per_page' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJson(fn ($json) => $json->where('meta.last_page', fn ($last) => $last >= 2)->etc());
})->with([
    'patients' => 'clinic.patients.index',
    'services' => 'clinic.line-items.index',
    'sessions' => 'clinic.sessions.index',
    'transactions' => 'clinic.transactions.index',
    'invoices' => 'clinic.invoices.index',
    'staff' => 'clinic.members.index',
    'activity log' => 'clinic.activity.index',
    'attendance' => 'clinic.attendance.index',
    'discounts' => 'clinic.discounts.index',
    'payouts' => 'clinic.payouts.index',
    'progress notes' => 'clinic.progress-notes.index',
    'patient intake links' => 'clinic.patient-form-invitations.index',
]);

test('internal list endpoints are paginated and honour per_page', function (string $route, Closure $parameters) {
    Sanctum::actingAs(User::factory()->superAdmin()->create());
    User::factory()->superAdmin()->create();
    $admin = ClinicUser::factory()->admin()->create();
    ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Clinic::factory()->create();

    $this->getJson(route($route, [...$parameters($admin), 'per_page' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJson(fn ($json) => $json->where('meta.last_page', fn ($last) => $last >= 2)->etc());
})->with([
    'super admins' => ['internal.super-admins.index', fn () => []],
    'clinics' => ['internal.clinics.index', fn () => []],
    'clinic users' => ['internal.clinics.users.index', fn (ClinicUser $admin) => ['clinic' => $admin->clinic_id]],
    'activity log' => ['internal.activity.index', fn () => []],
]);

test('a page size above 100 is rejected', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->getJson(route('internal.super-admins.index', ['per_page' => 500]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});
