<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runDevguardForSarif(array $args): array
{
    $bin = realpath(__DIR__ . '/../../bin/devguard');
    $process = new Process(array_merge([$bin], $args));
    $process->run();
    return [
        'stdout' => $process->getOutput() . $process->getErrorOutput(),
        'exit' => $process->getExitCode() ?? 1,
    ];
}

function tmpSarifPath(): string
{
    return sys_get_temp_dir() . '/devguard-sarif-' . uniqid('', true) . '.sarif';
}

it('--sarif=path writes a valid SARIF file alongside console output', function () {
    $path = tmpSarifPath();

    $r = runDevguardForSarif([
        'run', 'env',
        '--path=' . fixturePath('sample-laravel-app-bad'),
        '--sarif=' . $path,
    ]);

    expect($r['exit'])->toBe(1); // bad fixture has failures
    // Additive: console output AND sarif confirmation both present
    expect($r['stdout'])->toContain('Failed.');
    expect($r['stdout'])->toContain('SARIF written to');
    expect(file_exists($path))->toBeTrue();

    $sarif = json_decode((string) file_get_contents($path), true);
    expect($sarif)->toBeArray();
    expect($sarif['version'])->toBe('2.1.0');
    expect($sarif['runs'][0]['tool']['driver']['name'])->toBe('DevGuard');
    // Bad fixture has known failures → at least one result emitted.
    expect(count($sarif['runs'][0]['results']))->toBeGreaterThan(0);

    @unlink($path);
});

it('respects baseline filtering — suppressed issues do NOT appear in SARIF', function () {
    $fixture = fixturePath('sample-laravel-app-bad');
    $baseline = $fixture . '/devguard-baseline.json';
    if (is_file($baseline)) {
        unlink($baseline);
    }

    // Step 1: bake the current state into the baseline
    $gen = runDevguardForSarif(['baseline', '--path=' . $fixture]);
    expect($gen['exit'])->toBe(0);

    // Step 2: emit SARIF — should contain ZERO results (everything baselined)
    $sarifPath = tmpSarifPath();
    $r = runDevguardForSarif([
        'run', 'env',
        '--path=' . $fixture,
        '--sarif=' . $sarifPath,
    ]);

    expect($r['exit'])->toBe(0); // baselined → no failures
    $sarif = json_decode((string) file_get_contents($sarifPath), true);
    expect($sarif['runs'][0]['results'])->toBe([]);

    if (is_file($baseline)) {
        unlink($baseline);
    }
    if (is_file($sarifPath)) {
        unlink($sarifPath);
    }
});

it('errors cleanly when --sarif=path points to a non-existent directory', function () {
    $r = runDevguardForSarif([
        'run', 'env',
        '--path=' . fixturePath('sample-laravel-app-good'),
        '--sarif=/this/dir/definitely/does/not/exist/findings.sarif',
    ]);

    expect($r['exit'])->toBe(2);
    expect($r['stdout'])->toContain('directory does not exist');
});
