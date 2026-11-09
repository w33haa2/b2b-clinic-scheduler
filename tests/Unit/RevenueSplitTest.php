<?php

use App\Enums\DiscountTarget;
use App\Support\RevenueSplit;

/*
 * The split is the heart of the payout module: whatever happens, the two
 * sides must add up to what the patient actually owes.
 */

test('a gross amount is split by the share, with nothing discounted', function () {
    $split = RevenueSplit::of('1000.00', '0.00', DiscountTarget::Clinic, '60.00');

    expect($split->worker)->toBe('600.00')
        ->and($split->clinic)->toBe('400.00')
        ->and($split->net())->toBe('1000.00');
});

test('a discount on the clinic side leaves the staff member whole', function () {
    $split = RevenueSplit::of('1000.00', '100.00', DiscountTarget::Clinic, '60.00');

    expect($split->worker)->toBe('600.00')
        ->and($split->clinic)->toBe('300.00')
        ->and($split->net())->toBe('900.00');
});

test('a discount on the worker side leaves the clinic whole', function () {
    $split = RevenueSplit::of('1000.00', '100.00', DiscountTarget::Worker, '60.00');

    expect($split->worker)->toBe('500.00')
        ->and($split->clinic)->toBe('400.00')
        ->and($split->net())->toBe('900.00');
});

test('a discount bigger than the side it targets spills onto the other side instead of going negative', function () {
    // The clinic's cut is only 400.00, so the last 100.00 comes off the worker.
    $split = RevenueSplit::of('1000.00', '500.00', DiscountTarget::Clinic, '60.00');

    expect($split->worker)->toBe('500.00')
        ->and($split->clinic)->toBe('0.00')
        ->and($split->net())->toBe('500.00');
});

test('a discount can never take more than the item is worth', function () {
    $split = RevenueSplit::of('100.00', '250.00', DiscountTarget::Worker, '60.00');

    expect($split->discount)->toBe('100.00')
        ->and($split->worker)->toBe('0.00')
        ->and($split->clinic)->toBe('0.00')
        ->and($split->net())->toBe('0.00');
});

test('the two sides always add back up to the net, whatever the share', function (string $share, string $gross, string $discount) {
    foreach ([DiscountTarget::Clinic, DiscountTarget::Worker] as $target) {
        $split = RevenueSplit::of($gross, $discount, $target, $share);

        expect($split->net())->toBe(bcsub($split->gross, $split->discount, 2));
    }
})->with([
    'a third to staff, odd amount' => ['33.33', '999.99', '12.34'],
    'everything to staff' => ['100.00', '750.50', '100.00'],
    'nothing to staff' => ['0.00', '750.50', '100.00'],
    'rounding at the half cent' => ['50.00', '0.01', '0.00'],
]);
