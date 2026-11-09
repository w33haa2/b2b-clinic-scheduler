<?php

use App\Actions\Clinics\SyncClinicPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permissions added to the Clinic Worker defaults with attendance,
     * discounts and payouts. New clinics get them from ClinicRoleKey; this
     * gives them to the Worker role of clinics that already exist.
     *
     * The API narrows each of these to the member's own records: a worker
     * records their own attendance, sees their own payouts, and only ever
     * creates personal discounts.
     *
     * @var list<string>
     */
    private array $permissions = [
        'clinic.attendance.index',
        'clinic.attendance.store',
        'clinic.attendance.update',
        'clinic.discounts.index',
        'clinic.discounts.show',
        'clinic.discounts.store',
        'clinic.discounts.update',
        'clinic.discounts.destroy',
        'clinic.payouts.index',
        'clinic.payouts.show',
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
