<?php

declare(strict_types=1);

use DevGuard\Core\Baseline\Baseline;
use DevGuard\Core\Baseline\BaselineLoader;
use DevGuard\Results\RuleResult;
use DevGuard\Results\ToolReport;

function tmpBaseline(): string
{
    return sys_get_temp_dir() . '/devguard-baseline-' . uniqid('', true) . '.json';
}

it('returns an empty Baseline when the file does not exist', function () {
    $b = (new BaselineLoader())->load('/tmp/definitely-not-a-baseline.json');

    expect($b->size())->toBe(0);
});

it('round-trips: save a report, load it back, signatures match', function () {
    $report = new ToolReport('arch', 'Architecture');
    $report->add(RuleResult::fail('fat_controller', 'Controller has 500 lines', 'app/X.php'));
    $report->add(RuleResult::warn('service_layer', 'app/Services missing'));

    $path = tmpBaseline();
    $count = (new BaselineLoader())->save($path, [$report], ['devguard' => '0.6.0']);

    expect($count)->toBe(2);

    $loaded = (new BaselineLoader())->load($path);
    expect($loaded->size())->toBe(2);

    foreach ($report->results() as $r) {
        expect($loaded->hasSignature(Baseline::signatureFor($r)))->toBeTrue();
    }

    @unlink($path);
});

it('does not bake passing results into the baseline', function () {
    $report = new ToolReport('x', 'X');
    $report->add(RuleResult::pass('all_good', 'nothing to see'));
    $report->add(RuleResult::fail('bad', 'broken', 'x.php'));

    $path = tmpBaseline();
    $count = (new BaselineLoader())->save($path, [$report]);

    expect($count)->toBe(1);

    @unlink($path);
});

it('throws on a version mismatch instead of silently re-introducing 200 issues', function () {
    $path = tmpBaseline();
    file_put_contents($path, json_encode(['version' => 999, 'issues' => []]));

    expect(fn () => (new BaselineLoader())->load($path))
        ->toThrow(RuntimeException::class, 'version mismatch');

    @unlink($path);
});

it('writes deterministic output (sorted) so git diffs stay clean', function () {
    $report = new ToolReport('x', 'X');
    // Add in mixed order — file should still write sorted by rule then file.
    $report->add(RuleResult::fail('z_rule', 'msg', 'z.php'));
    $report->add(RuleResult::fail('a_rule', 'msg', 'a.php'));
    $report->add(RuleResult::fail('a_rule', 'msg', 'b.php'));

    $path = tmpBaseline();
    (new BaselineLoader())->save($path, [$report]);
    $first = file_get_contents($path);

    // Save a second time with the same data — file should be byte-identical
    // (modulo the generated_at timestamp).
    (new BaselineLoader())->save($path, [$report]);
    $second = file_get_contents($path);

    // Strip the timestamp line so we can compare structure.
    $stripTimestamp = fn ($s) => (string) preg_replace('/"generated_at":\s*"[^"]+"/', '"generated_at":"X"', $s);
    expect($stripTimestamp($first))->toBe($stripTimestamp($second));

    @unlink($path);
});
