<?php

use App\Models\ClinicRole;
use App\Models\ClinicUser;
use Laravel\Sanctum\Sanctum;

test('the settings start at the clinic defaults and list every member effective rate', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id, 'payout_share_percentage' => '70.00']);
    Sanctum::actingAs($admin->user);

    $response = $this->getJson(route('clinic.payout-settings.show', $admin->clinic_id))
        ->assertOk()
        ->assertJsonPath('data.settings.worker_share_percentage', '60.00')
        ->assertJsonPath('data.settings.clinic_share_percentage', '40.00')
        ->assertJsonPath('data.settings.include_pending_payments', true)
        ->assertJsonPath('data.settings.require_attendance', false)
        ->assertJsonCount(2, 'data.members');

    $members = collect($response->json('data.members'))->keyBy('id');

    expect($members[$worker->id]['effective_share_percentage'])->toBe('70.00')
        ->and($members[$worker->id]['uses_override'])->toBeTrue()
        ->and($members[$admin->id]['effective_share_percentage'])->toBe('60.00')
        ->and($members[$admin->id]['uses_override'])->toBeFalse();
});

test('an admin saves the split, the rules and the per-member rates in one request', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.payout-settings.update', $admin->clinic_id), [
        'worker_share_percentage' => '55.50',
        'include_pending_payments' => false,
        'require_attendance' => true,
        'member_shares' => [
            ['clinic_user_id' => $worker->id, 'share_percentage' => '80.00'],
            ['clinic_user_id' => $admin->id, 'share_percentage' => null],
        ],
    ])->assertOk()
        ->assertJsonPath('data.settings.worker_share_percentage', '55.50')
        ->assertJsonPath('data.settings.clinic_share_percentage', '44.50')
        ->assertJsonPath('data.settings.require_attendance', true);

    $this->assertDatabaseHas('clinic_payout_settings', [
        'clinic_id' => $admin->clinic_id,
        'worker_share_percentage' => '55.50',
        'include_pending_payments' => false,
        'require_attendance' => true,
    ]);
    $this->assertDatabaseHas('clinic_users', ['id' => $worker->id, 'payout_share_percentage' => '80.00']);
    $this->assertDatabaseHas('clinic_users', ['id' => $admin->id, 'payout_share_percentage' => null]);
});

test('a null rate puts a member back on the clinic default', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $worker = ClinicUser::factory()->worker()->create(['clinic_id' => $admin->clinic_id, 'payout_share_percentage' => '90.00']);
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.payout-settings.update', $admin->clinic_id), [
        'member_shares' => [['clinic_user_id' => $worker->id, 'share_percentage' => null]],
    ])->assertOk();

    expect($worker->refresh()->payout_share_percentage)->toBeNull();
});

test('a share outside nought to a hundred is refused', function (array $payload, string $field) {
    $admin = ClinicUser::factory()->admin()->create();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.payout-settings.update', $admin->clinic_id), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'over a hundred' => [['worker_share_percentage' => '120.00'], 'worker_share_percentage'],
    'negative' => [['worker_share_percentage' => '-5.00'], 'worker_share_percentage'],
    'three decimals' => [['worker_share_percentage' => '60.555'], 'worker_share_percentage'],
]);

test('a member of another clinic cannot be given a rate here', function () {
    $admin = ClinicUser::factory()->admin()->create();
    $foreign = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($admin->user);

    $this->putJson(route('clinic.payout-settings.update', $admin->clinic_id), [
        'member_shares' => [['clinic_user_id' => $foreign->id, 'share_percentage' => '90.00']],
    ])->assertUnprocessable()->assertJsonValidationErrors('member_shares.0.clinic_user_id');
});

test('payout settings are Clinic Admin only, whatever a role grants', function () {
    $role = ClinicRole::factory()->withPermissions(['clinic.payouts.index', 'clinic.payouts.show'])->create();
    $member = ClinicUser::factory()->withRole($role)->create();
    Sanctum::actingAs($member->user);

    $this->getJson(route('clinic.payout-settings.show', $role->clinic_id))->assertForbidden();
    $this->putJson(route('clinic.payout-settings.update', $role->clinic_id), [])->assertForbidden();
});
