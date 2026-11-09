<?php

use App\Actions\Clinics\SyncClinicPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Changing an enrolled patient's plan (service, usual slot) is part of the
     * Clinic Worker defaults; give it to the Worker role of existing clinics.
     */
    public function up(): void
    {
        app(SyncClinicPermissions::class)->handle();

        $permissionId = DB::table('clinic_permissions')->where('name', 'clinic.daily-sessions.patients.update')->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('clinic_roles')->where('key', 'worker')->pluck('id')->each(
            fn (int $roleId) => DB::table('clinic_role_permission')->insertOrIgnore([
                'clinic_role_id' => $roleId,
                'clinic_permission_id' => $permissionId,
            ]),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionId = DB::table('clinic_permissions')->where('name', 'clinic.daily-sessions.patients.update')->value('id');

        DB::table('clinic_role_permission')
            ->whereIn('clinic_role_id', DB::table('clinic_roles')->where('key', 'worker')->pluck('id'))
            ->where('clinic_permission_id', $permissionId)
            ->delete();
    }
};
