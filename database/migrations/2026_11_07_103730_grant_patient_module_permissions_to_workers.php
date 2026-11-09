<?php

use App\Actions\Clinics\SyncClinicPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permissions added to the Clinic Worker defaults with the patient
     * module. New clinics get them from ClinicRoleKey; this gives them to the
     * Worker role of clinics that already exist.
     *
     * Building the form is deliberately not here: it changes what every
     * patient record looks like, so it stays with Clinic Admins until an
     * admin grants it to a role.
     *
     * @var list<string>
     */
    private array $permissions = [
        'clinic.patients.history.index',
        'clinic.patients.progress-notes.index',
        'clinic.patients.progress-notes.store',
        'clinic.patients.progress-notes.update',
        // Author-scoped in the controller: a member can remove their own note,
        // a Clinic Admin can remove any.
        'clinic.patients.progress-notes.destroy',
        'clinic.progress-notes.index',
        'clinic.patient-form.show',
        'clinic.patient-form-invitations.index',
        'clinic.patient-form-invitations.store',
        'clinic.patient-form-invitations.resend',
        'clinic.patient-form-invitations.destroy',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The permission rows are normally created after migrating; these are needed now.
        app(SyncClinicPermissions::class)->handle();

        $permissionIds = DB::table('clinic_permissions')->whereIn('name', $this->permissions)->pluck('id');

        DB::table('clinic_roles')->where('key', 'worker')->pluck('id')->each(
            fn (int $roleId) => DB::table('clinic_role_permission')->insertOrIgnore(
                $permissionIds->map(fn (int $permissionId): array => ['clinic_role_id' => $roleId, 'clinic_permission_id' => $permissionId])->all(),
            ),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionIds = DB::table('clinic_permissions')->whereIn('name', $this->permissions)->pluck('id');
        $workerRoleIds = DB::table('clinic_roles')->where('key', 'worker')->pluck('id');

        DB::table('clinic_role_permission')
            ->whereIn('clinic_role_id', $workerRoleIds)
            ->whereIn('clinic_permission_id', $permissionIds)
            ->delete();
    }
};
