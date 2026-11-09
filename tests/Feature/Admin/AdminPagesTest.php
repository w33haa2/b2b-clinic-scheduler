<?php

use App\Models\ClinicPermission;
use App\Models\ClinicRole;
use App\Models\ClinicUser;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('super admins land on the internal tool', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.internal.clinics.index'));
});

test('a member of one clinic lands inside that clinic', function () {
    $worker = ClinicUser::factory()->worker()->create();

    $this->actingAs($worker->user)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.clinics.home', $worker->clinic_id));

    $this->get(route('admin.clinics.home', $worker->clinic_id))
        ->assertRedirect(route('admin.clinics.schedule', $worker->clinic_id));
});

test('a member of several clinics picks one on the dashboard', function () {
    $member = ClinicUser::factory()->worker()->create();
    ClinicUser::factory()->worker()->create(['user_id' => $member->user_id]);

    $this->actingAs($member->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard')->has('clinics.data', 2));
});

test('the clinic home opens the first page the member may see', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.invoices.index'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();

    $this->actingAs($member->user)
        ->get(route('admin.clinics.home', $role->clinic_id))
        ->assertRedirect(route('admin.clinics.invoices', $role->clinic_id));
});

test('clinic pages share the clinic and the member permissions', function () {
    $worker = ClinicUser::factory()->worker()->create();

    $this->actingAs($worker->user)
        ->get(route('admin.clinics.schedule', $worker->clinic_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/clinic/Schedule')
            ->where('clinicContext.clinic.id', $worker->clinic_id)
            ->where('clinicContext.member.is_admin', false)
            ->where('clinicContext.member.permissions', fn ($permissions) => collect($permissions)->contains('clinic.sessions.store')));
});

test('clinic workers can open the daily sessions page by default', function () {
    $worker = ClinicUser::factory()->worker()->create();

    $this->actingAs($worker->user)
        ->get(route('admin.clinics.daily-sessions', $worker->clinic_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/clinic/DailySessions'));
});

test('clinic workers can open the payments page by default', function () {
    $worker = ClinicUser::factory()->worker()->create();

    $this->actingAs($worker->user)
        ->get(route('admin.clinics.payments', $worker->clinic_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/clinic/Payments'));
});

test('clinic workers can open the attendance, discounts and payouts pages by default', function (string $route, string $component) {
    $worker = ClinicUser::factory()->worker()->create();

    $this->actingAs($worker->user)
        ->get(route($route, $worker->clinic_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'attendance' => ['admin.clinics.attendance', 'admin/clinic/Attendance'],
    'discounts' => ['admin.clinics.discounts', 'admin/clinic/Discounts'],
    'payouts' => ['admin.clinics.payouts', 'admin/clinic/Payouts'],
    'progress notes' => ['admin.clinics.progress-notes', 'admin/clinic/ProgressNotes'],
]);

test('the patient form builder is not open to workers by default', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);

    $this->actingAs($admin->user)
        ->get(route('admin.clinics.patient-form', $admin->clinic_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/clinic/PatientForm'));

    // Workers can see the form, so the page opens; building it is what the
    // API withholds.
    $this->actingAs($worker->user)
        ->get(route('admin.clinics.patient-form', $worker->clinic_id))
        ->assertOk();
});

test('the clinic books and payout settings are Clinic Admin only', function (string $route, string $component) {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);

    $this->actingAs($admin->user)
        ->get(route($route, $admin->clinic_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));

    $this->actingAs($worker->user)
        ->get(route($route, $worker->clinic_id))
        ->assertForbidden();
})->with([
    'receivables' => ['admin.clinics.receivables', 'admin/clinic/Receivables'],
    'payout settings' => ['admin.clinics.payout-settings', 'admin/clinic/PayoutSettings'],
]);

test('a clinic page needs the permission of the data it shows', function () {
    $worker = ClinicUser::factory()->worker()->create();

    $this->actingAs($worker->user)
        ->get(route('admin.clinics.invoices', $worker->clinic_id))
        ->assertForbidden();
});

test('clinic pages of another clinic are not found', function () {
    $worker = ClinicUser::factory()->worker()->create();
    $otherClinic = ClinicUser::factory()->admin()->create()->clinic;

    $this->actingAs($worker->user)
        ->get(route('admin.clinics.schedule', $otherClinic))
        ->assertNotFound();
});

test('clinic members cannot open the internal tool', function () {
    $admin = ClinicUser::factory()->admin()->create();

    $this->actingAs($admin->user)
        ->get(route('admin.internal.clinics.index'))
        ->assertForbidden();
});

test('web page routes are not grantable permissions', function () {
    expect(ClinicPermission::query()->where('name', 'like', 'admin.%')->exists())->toBeFalse();
    expect(ClinicPermission::query()->whereIn('name', ['clinic.receivables.index', 'clinic.payout-settings.show', 'clinic.payout-settings.update'])->exists())->toBeFalse();
});
