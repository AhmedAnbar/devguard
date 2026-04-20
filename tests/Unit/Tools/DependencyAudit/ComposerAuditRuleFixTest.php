<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Tools\DependencyAudit\Rules\ComposerAuditRule;

// We reuse fakeComposer() / fakeProjectContext() defined in
// ComposerAuditRuleTest.php — function declarations are global in PHP.

it('proposes one fix per package even when the package has multiple advisories', function () {
    $payload = json_encode([
        'advisories' => [
            'phpseclib/phpseclib' => [
                ['advisoryId' => 'PKSA-1', 'title' => 'HMAC timing'],
                ['advisoryId' => 'PKSA-2', 'title' => 'AES-CBC padding oracle'],
            ],
            'aws/aws-sdk-php' => [
                ['advisoryId' => 'PKSA-3', 'title' => 'CloudFront injection'],
            ],
        ],
    ]);

    $fakeComposer = fakeComposer($payload, 5); // bitmask exit
    $ctx = fakeProjectContext();

    $rule = new ComposerAuditRule(composerBinary: $fakeComposer);
    $fixes = $rule->proposeFixes($ctx);

    // Two packages → two fixes (NOT three; phpseclib's two advisories collapse).
    expect($fixes)->toHaveCount(2);

    $targets = array_map(fn ($f) => $f->target, $fixes);
    expect($targets)->toContain('phpseclib/phpseclib');
    expect($targets)->toContain('aws/aws-sdk-php');

    // The phpseclib fix's description mentions "2 advisories"; aws's mentions "1 advisory".
    $byTarget = [];
    foreach ($fixes as $f) {
        $byTarget[$f->target] = $f;
    }
    expect($byTarget['phpseclib/phpseclib']->description)->toContain('2 advisories');
    expect($byTarget['aws/aws-sdk-php']->description)->toContain('1 advisory');

    // Payload carries the package name for applyFix to consume.
    expect($byTarget['aws/aws-sdk-php']->payload)->toBe(['package' => 'aws/aws-sdk-php']);
});

it('proposes no fixes when there are no advisories', function () {
    $payload = json_encode(['advisories' => [], 'abandoned' => []]);
    $fakeComposer = fakeComposer($payload, 0);
    $ctx = fakeProjectContext();

    $fixes = (new ComposerAuditRule(composerBinary: $fakeComposer))->proposeFixes($ctx);
    expect($fixes)->toBe([]);
});

it('proposes no fixes when composer.lock is missing (skips invoking composer)', function () {
    $root = sys_get_temp_dir() . '/devguard-fix-no-lock-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    $ctx = ProjectContext::detect($root);

    // /bin/false would crash if invoked — proves we never call out.
    $fixes = (new ComposerAuditRule(composerBinary: '/bin/false'))->proposeFixes($ctx);
    expect($fixes)->toBe([]);
});

it('proposes no fixes when composer output is unparseable', function () {
    $fakeComposer = fakeComposer('not json at all', 127);
    $ctx = fakeProjectContext();

    $fixes = (new ComposerAuditRule(composerBinary: $fakeComposer))->proposeFixes($ctx);
    expect($fixes)->toBe([]);
});
