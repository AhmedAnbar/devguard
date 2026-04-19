<?php

declare(strict_types=1);

use DevGuard\Results\CheckResult;
use DevGuard\Results\ToolReport;

it('reports failures correctly', function () {
    $report = new ToolReport(tool: 't', title: 'T');
    $report->add(CheckResult::pass('a', 'ok'));
    $report->add(CheckResult::fail('b', 'oops', 5, 'fix it'));

    expect($report->hasFailures())->toBeTrue();
    expect($report->hasWarnings())->toBeFalse();
});

it('reports warnings without failures', function () {
    $report = new ToolReport(tool: 't', title: 'T');
    $report->add(CheckResult::warn('a', 'mild', 4, 'tighten it'));

    expect($report->hasFailures())->toBeFalse();
    expect($report->hasWarnings())->toBeTrue();
});

it('only includes suggestions for non-passing results that have one', function () {
    $report = new ToolReport(tool: 't', title: 'T');
    $report->add(CheckResult::pass('a', 'ok'));
    $report->add(CheckResult::warn('b', 'mild', 0, 'do this'));
    $report->add(CheckResult::fail('c', 'bad', 0, null));

    $suggestions = $report->suggestions();
    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]->name)->toBe('b');
});

it('serializes to array with score and pass/fail summary', function () {
    $report = new ToolReport(tool: 'deploy', title: 'Deploy', score: 85);
    $report->add(CheckResult::pass('a', 'ok'));

    $arr = $report->toArray();
    expect($arr['tool'])->toBe('deploy');
    expect($arr['score'])->toBe(85);
    expect($arr['passed'])->toBeTrue();
    expect($arr['results'])->toHaveCount(1);
});
