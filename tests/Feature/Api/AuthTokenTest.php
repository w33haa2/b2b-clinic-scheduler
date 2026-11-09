<?php

use App\Models\User;

test('a user exchanges valid credentials for a bearer token', function () {
    $user = User::factory()->create();

    $token = $this->postJson(route('tokens.store'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'phone',
    ])->assertCreated()->json('token');

    $this->withToken($token)->getJson(route('me'))->assertOk()->assertJsonPath('data.id', $user->id);
});

test('a wrong password returns 422 without a token', function () {
    $user = User::factory()->create();

    $this->postJson(route('tokens.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'phone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
});

test('a revoked token can no longer be used', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;

    $this->withToken($token)->deleteJson(route('tokens.destroy'))->assertNoContent();

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(route('me'))->assertUnauthorized();
});

test('the api returns 401 without a token', function () {
    $this->getJson(route('me'))->assertUnauthorized();
});
