<?php

use App\Models\ActivityLog;
use App\Models\CalendarFilter;
use App\Models\ClinicUser;
use Laravel\Sanctum\Sanctum;

test('a member saves, renames and deletes their own schedule filters', function () {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $id = $this->postJson(route('clinic.calendar-filters.store', $worker->clinic_id), [
        'name' => 'My unpaid daily patients',
        'rules' => [
            'match' => 'all',
            'conditions' => [
                ['field' => 'type', 'operator' => 'is', 'values' => ['daily']],
                ['field' => 'payment', 'operator' => 'is', 'values' => ['unpaid', 'past_due']],
            ],
        ],
    ])->assertCreated()->assertJsonPath('data.rules.conditions.1.values', ['unpaid', 'past_due'])->json('data.id');

    $this->patchJson(route('clinic.calendar-filters.update', [$worker->clinic_id, $id]), ['name' => 'Unpaid daily'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Unpaid daily');
    $this->getJson(route('clinic.calendar-filters.index', $worker->clinic_id))->assertJsonCount(1, 'data');
    $this->deleteJson(route('clinic.calendar-filters.destroy', [$worker->clinic_id, $id]))->assertNoContent();

    expect(ActivityLog::query()->where('action', 'like', 'calendar_filter.%')->orderBy('id')->pluck('action')->all())
        ->toBe(['calendar_filter.created', 'calendar_filter.updated', 'calendar_filter.deleted']);
});

test('filters of other members are invisible, even to a clinic admin', function () {
    $filter = CalendarFilter::factory()->create();
    $admin = ClinicUser::factory()->admin()->create(['clinic_id' => $filter->clinic_id]);
    Sanctum::actingAs($admin->user);

    $this->getJson(route('clinic.calendar-filters.index', $filter->clinic_id))->assertJsonCount(0, 'data');
    $this->patchJson(route('clinic.calendar-filters.update', [$filter->clinic_id, $filter]), ['name' => 'Mine now'])->assertNotFound();
    $this->deleteJson(route('clinic.calendar-filters.destroy', [$filter->clinic_id, $filter]))->assertNotFound();

    $this->assertModelExists($filter);
});

test('a filter of another clinic is not found', function () {
    $filter = CalendarFilter::factory()->create();
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->deleteJson(route('clinic.calendar-filters.destroy', [$worker->clinic_id, $filter]))->assertNotFound();
});

test('a filter only accepts known fields and operators', function (array $condition, string $error) {
    $worker = ClinicUser::factory()->worker()->create();
    Sanctum::actingAs($worker->user);

    $this->postJson(route('clinic.calendar-filters.store', $worker->clinic_id), [
        'name' => 'Broken',
        'rules' => ['match' => 'all', 'conditions' => [$condition]],
    ])->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'unknown field' => [['field' => 'clinic_id', 'operator' => 'is', 'values' => ['1']], 'rules.conditions.0.field'],
    'unknown operator' => [['field' => 'status', 'operator' => 'like', 'values' => ['DONE']], 'rules.conditions.0.operator'],
    'no values' => [['field' => 'status', 'operator' => 'is', 'values' => []], 'rules.conditions.0.values'],
    'extra key' => [['field' => 'status', 'operator' => 'is', 'values' => ['DONE'], 'sql' => 'x'], 'rules.conditions.0'],
]);
