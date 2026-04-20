<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\EnvAudit\EnvAuditTool;

it('passes all rules on a clean fixture', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));
    $report = (new EnvAuditTool())->run($ctx);

    expect($report->hasFailures())->toBeFalse();
    expect($report->hasWarnings())->toBeFalse();
});

it('detects missing keys, drift, and weak APP_KEY on a bad fixture', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-bad'));
    $report = (new EnvAuditTool())->run($ctx);

    expect($report->hasFailures())->toBeTrue();

    $byName = [];
    foreach ($report->results() as $r) {
        $byName[$r->name][] = $r;
    }

    // At least one missing key result (we have many in the fixture).
    expect($byName['missing_env_keys'] ?? [])->not->toBeEmpty();
    expect($byName['missing_env_keys'][0]->status)->toBe(Status::Fail);

    // The drift rule should flag EXTRA_LOCAL_ONLY_KEY as a warning.
    $driftMessages = array_map(fn ($r) => $r->message, $byName['drifted_env_keys'] ?? []);
    expect(implode("\n", $driftMessages))->toContain('EXTRA_LOCAL_ONLY_KEY');

    // Weak APP_KEY (it's missing entirely in the bad fixture's .env).
    expect($byName['weak_app_key'][0]->status)->toBe(Status::Fail);
});
