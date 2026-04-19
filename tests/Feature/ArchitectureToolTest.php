<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\ArchitectureEnforcer\ArchitectureTool;

it('passes all rules on a clean fixture', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));
    $report = (new ArchitectureTool())->run($ctx);

    expect($report->hasFailures())->toBeFalse();
    expect($report->hasWarnings())->toBeFalse();
});

it('detects fat controllers, complexity, and direct DB calls', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-bad'));
    $report = (new ArchitectureTool())->run($ctx);

    expect($report->hasFailures())->toBeTrue();

    $failNames = [];
    foreach ($report->results() as $r) {
        if ($r->status === Status::Fail) {
            $failNames[] = $r->name;
        }
    }

    expect($failNames)->toContain('folder_structure');
    expect($failNames)->toContain('business_logic_in_controller');
    expect($failNames)->toContain('direct_db_in_controller');
});

it('warns on missing service and repository layers', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-bad'));
    $report = (new ArchitectureTool())->run($ctx);

    $warnings = [];
    foreach ($report->results() as $r) {
        if ($r->status === Status::Warning) {
            $warnings[] = $r->name;
        }
    }

    expect($warnings)->toContain('service_layer');
    expect($warnings)->toContain('repository_layer');
});
