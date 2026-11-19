<?php

use App\Actions\Clinics\SyncClinicPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Arranging a makeup for a patient who misses a day is part of running
     * the schedule, which workers already do: they plot patients, move them
     * and change what they are billed. New clinics get this from
     * ClinicRoleKey; this gives it to clinics that already exist.
     *
     * @var list<string>
     */
    private array $permissions = [
        'clinic.sessions.members.reschedule.store',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The permission rows are normally created after migrating; this one is needed now.
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
