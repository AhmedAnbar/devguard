<?php

declare(strict_types=1);

use DevGuard\Results\CheckResult;
use DevGuard\Tools\DeployReadiness\Scorer;

it('starts at 100 with no results', function () {
    expect((new Scorer())->score([]))->toBe(100);
});

it('deducts the full impact for failures', function () {
    $results = [
        CheckResult::fail('a', 'msg', 15),
        CheckResult::fail('b', 'msg', 10),
    ];
    expect((new Scorer())->score($results))->toBe(75);
});

it('deducts half the impact for warnings (rounded down)', function () {
    $results = [
        CheckResult::warn('a', 'msg', 10),
        CheckResult::warn('b', 'msg', 5),
    ];
    expect((new Scorer())->score($results))->toBe(93);
});

it('does not deduct for passing checks', function () {
    $results = [
        CheckResult::pass('a', 'ok'),
        CheckResult::pass('b', 'ok'),
    ];
    expect((new Scorer())->score($results))->toBe(100);
});

it('floors the score at zero', function () {
    $results = [
        CheckResult::fail('a', 'msg', 200),
    ];
    expect((new Scorer())->score($results))->toBe(0);
});

it('mixes pass, warn, and fail correctly', function () {
    $results = [
        CheckResult::pass('a', 'ok'),
        CheckResult::warn('b', 'msg', 10),
        CheckResult::fail('c', 'msg', 15),
    ];
    expect((new Scorer())->score($results))->toBe(80);
});
