<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\DependencyAudit\Rules\ComposerAuditRule;

/**
 * Build a tiny shell script on disk that simulates `composer audit` by
 * printing a fixed payload and exiting with a chosen code. Lets us prove
 * the rule parses output regardless of exit code (the v0.2.1 regression).
 */
function fakeComposer(string $stdout, int $exitCode): string
{
    $tmp = sys_get_temp_dir() . '/devguard-fake-composer-' . uniqid('', true);
    mkdir($tmp);
    $script = $tmp . '/composer';
    $body = "#!/bin/sh\ncat <<'EOF'\n{$stdout}\nEOF\nexit {$exitCode}\n";
    file_put_contents($script, $body);
    chmod($script, 0o755);
    return $script;
}

function fakeProjectContext(): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-fake-project-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/composer.lock', '{}');
    return ProjectContext::detect($root);
}

it('parses advisories even when composer exits non-zero (severity bitmask)', function () {
    $payload = json_encode([
        'advisories' => [
            'aws/aws-sdk-php' => [[
                'advisoryId' => 'PKSA-test',
                'packageName' => 'aws/aws-sdk-php',
                'title' => 'Test high advisory',
                'cve' => 'CVE-TEST',
                'link' => 'https://example.com',
                'severity' => 'high',
            ]],
            'foo/bar' => [[
                'advisoryId' => 'PKSA-low',
                'packageName' => 'foo/bar',
                'title' => 'Test low advisory',
                'severity' => 'low',
            ]],
        ],
        'abandoned' => [],
    ]);

    $fakeComposer = fakeComposer($payload, 5); // exit 5 = high (1) + low (4)
    $ctx = fakeProjectContext();

    $rule = new ComposerAuditRule(composerBinary: $fakeComposer);
    $results = $rule->run($ctx);

    // 2 advisories -> 2 RuleResults, none of them "command failed".
    expect($results)->toHaveCount(2);
    expect($results[0]->status)->toBe(Status::Fail);    // high
    expect($results[1]->status)->toBe(Status::Warning); // low
    foreach ($results as $r) {
        expect($r->message)->not->toContain('composer audit failed');
    }
});

it('returns pass when there are no advisories', function () {
    $payload = json_encode(['advisories' => [], 'abandoned' => []]);
    $fakeComposer = fakeComposer($payload, 0);
    $ctx = fakeProjectContext();

    $results = (new ComposerAuditRule(composerBinary: $fakeComposer))->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
});

it('reports a real failure when composer outputs non-JSON', function () {
    $fakeComposer = fakeComposer('this is not json at all', 127);
    $ctx = fakeProjectContext();

    $results = (new ComposerAuditRule(composerBinary: $fakeComposer))->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Fail);
    expect($results[0]->message)->toContain('composer audit failed');
    expect($results[0]->message)->toContain('exit 127');
});

it('warns when composer.lock is missing without invoking composer', function () {
    $root = sys_get_temp_dir() . '/devguard-no-lock-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    $ctx = ProjectContext::detect($root);

    // Use /bin/false as composer — would crash if invoked.
    $results = (new ComposerAuditRule(composerBinary: '/bin/false'))->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Warning);
    expect($results[0]->message)->toContain('composer.lock is missing');
});
