<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('admin area is served under the admin prefix', function () {
    $this->get('/admin')->assertRedirect('/admin/login');

    $this->get('/admin/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/auth/Login'));
});

test('authenticated users are redirected to the admin dashboard after login', function () {
    $user = User::factory()->create();

    $this->post('/admin/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/admin');
});
