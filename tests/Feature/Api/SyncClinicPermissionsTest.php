<?php

use App\Models\ClinicPermission;

test('migrating creates a permission for every permission-guarded clinic route', function () {
    $permissions = ClinicPermission::query()->pluck('name');

    expect($permissions)
        ->toContain('clinic.patients.index', 'clinic.sessions.members.payment.update', 'clinic.invoices.store')
        ->not->toContain('clinic.show', 'clinic.update', 'clinic.roles.store', 'clinic.members.store', 'internal.clinics.index');
});

test('the sync command removes permissions whose route no longer exists', function () {
    ClinicPermission::query()->create(['name' => 'clinic.removed.index']);

    $this->artisan('clinic:sync-permissions')
        ->expectsOutputToContain('- clinic.removed.index')
        ->assertSuccessful();

    $this->assertDatabaseMissing('clinic_permissions', ['name' => 'clinic.removed.index']);
});
