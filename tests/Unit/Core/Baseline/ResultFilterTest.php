<?php

declare(strict_types=1);

use DevGuard\Core\Baseline\Baseline;
use DevGuard\Core\Baseline\IgnoreAnnotationParser;
use DevGuard\Core\Baseline\ResultFilter;
use DevGuard\Results\RuleResult;
use DevGuard\Results\ToolReport;

it('drops results whose signature appears in the baseline', function () {
    $report = new ToolReport('x', 'X');
    $r1 = RuleResult::fail('rule_a', 'this is in baseline', 'a.php');
    $r2 = RuleResult::fail('rule_b', 'this is NEW', 'b.php');
    $report->add($r1);
    $report->add($r2);

    $baseline = new Baseline([Baseline::signatureFor($r1) => true]);
    $filter = new ResultFilter($baseline, new IgnoreAnnotationParser(), '/tmp');

    $suppressed = $filter->apply($report);

    expect($suppressed)->toBe(1);
    expect($report->suppressedCount())->toBe(1);
    expect(count($report->results()))->toBe(1);
    expect($report->results()[0]->name)->toBe('rule_b');
});

it('never suppresses passing results — those are not issues', function () {
    $report = new ToolReport('x', 'X');
    $pass = RuleResult::pass('rule_x', 'all good');
    $report->add($pass);

    // Even if the pass somehow ends up in the baseline, the filter should
    // ignore baselining for passes (they cost nothing to leave in).
    $baseline = new Baseline([Baseline::signatureFor($pass) => true]);
    $filter = new ResultFilter($baseline, new IgnoreAnnotationParser(), '/tmp');

    $filter->apply($report);

    expect($report->suppressedCount())->toBe(0);
    expect(count($report->results()))->toBe(1);
});

it('drops results suppressed by an inline @devguard-ignore annotation', function () {
    $root = sys_get_temp_dir() . '/devguard-rfilter-' . uniqid('', true);
    mkdir($root);
    $relPath = 'src/Bad.php';
    mkdir($root . '/src');
    file_put_contents(
        $root . '/' . $relPath,
        "<?php\n\$x = DB::table('users')->get(); // @devguard-ignore: rule_db\n"
    );

    $report = new ToolReport('x', 'X');
    $report->add(new RuleResult(
        'rule_db',
        \DevGuard\Results\Status::Fail,
        'direct DB call',
        $relPath,
        2 // matches the line in the file above
    ));

    $filter = new ResultFilter(Baseline::empty(), new IgnoreAnnotationParser(), $root);
    $filter->apply($report);

    expect($report->suppressedCount())->toBe(1);
    expect(count($report->results()))->toBe(0);
});

it('does NOT suppress when the annotation targets a different rule', function () {
    $root = sys_get_temp_dir() . '/devguard-rfilter2-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/x.php', "<?php\n\$x = 1; // @devguard-ignore: some_other_rule\n");

    $report = new ToolReport('x', 'X');
    $report->add(new RuleResult('rule_db', \DevGuard\Results\Status::Fail, 'msg', 'x.php', 2));

    (new ResultFilter(Baseline::empty(), new IgnoreAnnotationParser(), $root))->apply($report);

    expect($report->suppressedCount())->toBe(0);
    expect(count($report->results()))->toBe(1);
});

it('ignores annotations for results without a file/line (deploy checks)', function () {
    $report = new ToolReport('x', 'X');
    $report->add(\DevGuard\Results\CheckResult::fail('debug_mode', 'APP_DEBUG enabled'));

    (new ResultFilter(Baseline::empty(), new IgnoreAnnotationParser(), '/tmp'))->apply($report);

    // Nothing in baseline, nothing annotation-suppressible (no file:line)
    // → everything passes through.
    expect($report->suppressedCount())->toBe(0);
    expect(count($report->results()))->toBe(1);
});
