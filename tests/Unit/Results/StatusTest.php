<?php

declare(strict_types=1);

use DevGuard\Results\Status;

it('exposes icons for each status', function () {
    expect(Status::Pass->icon())->toBe('✓');
    expect(Status::Warning->icon())->toBe('⚠');
    expect(Status::Fail->icon())->toBe('✗');
});

it('exposes colors for each status', function () {
    expect(Status::Pass->color())->toBe('green');
    expect(Status::Warning->color())->toBe('yellow');
    expect(Status::Fail->color())->toBe('red');
});

it('serializes via the backed value', function () {
    expect(Status::Pass->value)->toBe('pass');
    expect(Status::Warning->value)->toBe('warning');
    expect(Status::Fail->value)->toBe('fail');
});
