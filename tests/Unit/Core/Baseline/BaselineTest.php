<?php

declare(strict_types=1);

use DevGuard\Core\Baseline\Baseline;
use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;

it('produces a stable signature across runs for the same rule + file + message', function () {
    $r1 = RuleResult::fail('fat_controller', 'Controller has 401 lines', 'app/Http/Controllers/UserController.php');
    $r2 = RuleResult::fail('fat_controller', 'Controller has 401 lines', 'app/Http/Controllers/UserController.php');

    expect(Baseline::signatureFor($r1))->toBe(Baseline::signatureFor($r2));
});

it('produces different signatures when message changes (controller grows by one line)', function () {
    $r1 = RuleResult::fail('fat_controller', 'Controller has 401 lines', 'x.php');
    $r2 = RuleResult::fail('fat_controller', 'Controller has 402 lines', 'x.php');

    // Trade-off: line-count change invalidates the signature. Documented
    // in CLAUDE.md; acceptable for v1.
    expect(Baseline::signatureFor($r1))->not->toBe(Baseline::signatureFor($r2));
});

it('produces different signatures across different files', function () {
    $r1 = RuleResult::fail('rule_x', 'msg', 'a.php');
    $r2 = RuleResult::fail('rule_x', 'msg', 'b.php');

    expect(Baseline::signatureFor($r1))->not->toBe(Baseline::signatureFor($r2));
});

it('handles CheckResult (no file) without crashing', function () {
    $check = CheckResult::fail('debug_mode', 'APP_DEBUG is enabled in production');

    $sig = Baseline::signatureFor($check);

    expect($sig)->toBeString()->not->toBe('');
});

it('treats line numbers as irrelevant — does NOT include them in the signature', function () {
    // The same issue at different line numbers (because someone added a
    // comment block above) must produce the same signature. This is the
    // whole point of the line-number-free design.
    $r1 = new RuleResult('rule_x', \DevGuard\Results\Status::Fail, 'same message', 'same.php', 10);
    $r2 = new RuleResult('rule_x', \DevGuard\Results\Status::Fail, 'same message', 'same.php', 250);

    expect(Baseline::signatureFor($r1))->toBe(Baseline::signatureFor($r2));
});

it('lookups via hasSignature are O(1) set-style', function () {
    $sig = sha1('x');
    $b = new Baseline([$sig => true]);

    expect($b->hasSignature($sig))->toBeTrue();
    expect($b->hasSignature('not-there'))->toBeFalse();
    expect($b->size())->toBe(1);
});
