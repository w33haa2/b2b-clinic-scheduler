<?php

use App\Actions\Clinics\SyncClinicPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permissions added to the Clinic Worker defaults with daily sessions and
     * the payments page. New clinics get them from ClinicRoleKey; this gives
     * them to the Worker role of clinics that already exist.
     *
     * @var list<string>
     */
    private array $permissions = [
        'clinic.daily-sessions.index',
        'clinic.daily-sessions.show',
        'clinic.daily-sessions.store',
        'clinic.daily-sessions.update',
        'clinic.daily-sessions.patients.store',
        'clinic.daily-sessions.patients.destroy',
        'clinic.sessions.members.slot.update',
        'clinic.sessions.members.payment.update',
        'clinic.payments.index',
        'clinic.payments.show',
        'clinic.payments.prepay.store',
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
