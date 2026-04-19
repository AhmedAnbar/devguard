<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\DeployReadiness\DeployReadinessTool;

it('scores 100 on a clean fixture', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));
    $report = (new DeployReadinessTool())->run($ctx);

    expect($report->score)->toBe(100);
    expect($report->hasFailures())->toBeFalse();
});

it('flags violations on a bad fixture and produces a low score', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-bad'));
    $report = (new DeployReadinessTool())->run($ctx);

    expect($report->score)->toBeLessThan(60);
    expect($report->hasFailures())->toBeTrue();

    $byName = [];
    foreach ($report->results() as $r) {
        $byName[$r->name] = $r;
    }

    expect($byName['debug_mode']->status)->toBe(Status::Fail);
    expect($byName['cache_configured']->status)->toBe(Status::Fail);
    expect($byName['queue_configured']->status)->toBe(Status::Fail);
    expect($byName['rate_limit']->status)->toBe(Status::Fail);
    expect($byName['https_enforced']->status)->toBe(Status::Fail);
});
