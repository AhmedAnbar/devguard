<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runDevguard(array $args): array
{
    $bin = realpath(__DIR__ . '/../../bin/devguard');
    $process = new Process(array_merge([$bin], $args));
    $process->run();
    return [
        'stdout' => $process->getOutput() . $process->getErrorOutput(),
        'exit' => $process->getExitCode() ?? 1,
    ];
}

it('reports its version', function () {
    $r = runDevguard(['--version']);
    expect($r['exit'])->toBe(0);
    expect($r['stdout'])->toContain('DevGuard');
    // Version string is dynamic (Composer\InstalledVersions); just sanity-check
    // that *some* version follows the app name.
    expect($r['stdout'])->toMatch('/DevGuard\s+\S+/');
});

it('lists registered tools', function () {
    $r = runDevguard(['tools']);
    expect($r['exit'])->toBe(0);
    expect($r['stdout'])->toContain('deploy');
    expect($r['stdout'])->toContain('architecture');
});

it('exits 0 for a passing project', function () {
    $r = runDevguard(['run', 'deploy', '--path=' . fixturePath('sample-laravel-app-good')]);
    expect($r['exit'])->toBe(0);
});

it('exits 1 when checks fail', function () {
    $r = runDevguard(['run', 'deploy', '--path=' . fixturePath('sample-laravel-app-bad')]);
    expect($r['exit'])->toBe(1);
});

it('produces valid JSON in --json mode', function () {
    $r = runDevguard(['run', 'deploy', '--path=' . fixturePath('sample-laravel-app-good'), '--json']);

    $decoded = json_decode($r['stdout'], true);
    expect($decoded)->toBeArray();
    expect($decoded['tool'])->toBe('deploy');
    expect($decoded['score'])->toBe(100);
    expect($decoded['passed'])->toBeTrue();
});
