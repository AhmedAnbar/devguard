<?php

declare(strict_types=1);

use DevGuard\Core\Baseline\Baseline;
use DevGuard\Core\Output\SarifBuilder;
use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;
use DevGuard\Results\ToolReport;

function buildAndDecode(array $reports, string $version = '0.7.0'): array
{
    $json = (new SarifBuilder())->build($reports, $version);
    $decoded = json_decode($json, true);
    expect($decoded)->toBeArray();
    return $decoded;
}

it('emits SARIF 2.1.0 with the official schema URL and a single combined run', function () {
    $report = new ToolReport('deploy', 'Deploy Readiness Score');
    $report->add(CheckResult::fail('debug_mode', 'APP_DEBUG enabled'));

    $sarif = buildAndDecode([$report]);

    expect($sarif['version'])->toBe('2.1.0');
    expect($sarif['$schema'])->toContain('sarif-2.1.0');
    expect($sarif['runs'])->toHaveCount(1);
    expect($sarif['runs'][0]['tool']['driver']['name'])->toBe('DevGuard');
    expect($sarif['runs'][0]['tool']['driver']['version'])->toBe('0.7.0');
});

it('prefixes rule IDs with the tool name (deploy/debug_mode)', function () {
    $deploy = new ToolReport('deploy', 'Deploy');
    $deploy->add(CheckResult::fail('debug_mode', 'm1'));
    $arch = new ToolReport('architecture', 'Architecture');
    $arch->add(RuleResult::fail('fat_controller', 'm2', 'a.php'));

    $sarif = buildAndDecode([$deploy, $arch]);

    $ids = array_map(fn ($r) => $r['ruleId'], $sarif['runs'][0]['results']);
    expect($ids)->toContain('deploy/debug_mode');
    expect($ids)->toContain('architecture/fat_controller');
});

it('maps DevGuard status to SARIF level (Fail→error, Warning→warning)', function () {
    $report = new ToolReport('x', 'X');
    $report->add(RuleResult::fail('a', 'm', 'a.php'));
    $report->add(RuleResult::warn('b', 'm', 'b.php'));

    $sarif = buildAndDecode([$report]);

    $byId = [];
    foreach ($sarif['runs'][0]['results'] as $r) {
        $byId[$r['ruleId']] = $r;
    }
    expect($byId['x/a']['level'])->toBe('error');
    expect($byId['x/b']['level'])->toBe('warning');
});

it('does NOT emit passing results — SARIF is for issues only', function () {
    $report = new ToolReport('x', 'X');
    $report->add(RuleResult::pass('all_good', 'nothing wrong'));
    $report->add(RuleResult::fail('bad', 'broken', 'x.php'));

    $sarif = buildAndDecode([$report]);

    expect($sarif['runs'][0]['results'])->toHaveCount(1);
    expect($sarif['runs'][0]['results'][0]['ruleId'])->toBe('x/bad');
});

it('includes file location when the result has a file (and line if present)', function () {
    $report = new ToolReport('x', 'X');
    $report->add(new RuleResult('rule_x', \DevGuard\Results\Status::Fail, 'msg', 'app/X.php', 42));

    $sarif = buildAndDecode([$report]);
    $result = $sarif['runs'][0]['results'][0];

    expect($result['locations'][0]['physicalLocation']['artifactLocation']['uri'])->toBe('app/X.php');
    expect($result['locations'][0]['physicalLocation']['region']['startLine'])->toBe(42);
});

it('omits the locations key entirely for results without a file (deploy checks)', function () {
    $report = new ToolReport('deploy', 'Deploy');
    $report->add(CheckResult::fail('debug_mode', 'APP_DEBUG enabled'));

    $sarif = buildAndDecode([$report]);

    // SARIF allows omitting locations — better than emitting an empty array.
    expect(isset($sarif['runs'][0]['results'][0]['locations']))->toBeFalse();
});

it('partialFingerprints reuse the exact baseline signature so GitHub tracks issues across runs', function () {
    $r = RuleResult::fail('rule_x', 'msg', 'a.php');
    $report = new ToolReport('x', 'X');
    $report->add($r);

    $sarif = buildAndDecode([$report]);
    $sig = $sarif['runs'][0]['results'][0]['partialFingerprints']['devguardSignature/v1'];

    // The same hash function the baseline uses → fix-then-rerun cycles
    // don't re-flag the same issue as new in GitHub Code Scanning.
    expect($sig)->toBe(Baseline::signatureFor($r));
});

it('appends the suggestion to message.text when present', function () {
    $report = new ToolReport('x', 'X');
    $report->add(RuleResult::fail('rule_x', 'something is wrong', 'a.php', null, 'Run X to fix'));

    $sarif = buildAndDecode([$report]);
    $text = $sarif['runs'][0]['results'][0]['message']['text'];

    expect($text)->toContain('something is wrong');
    expect($text)->toContain('Suggestion: Run X to fix');
});

it('handles an empty report list — still produces valid SARIF with empty results', function () {
    $sarif = buildAndDecode([]);

    expect($sarif['version'])->toBe('2.1.0');
    expect($sarif['runs'][0]['results'])->toBe([]);
});
