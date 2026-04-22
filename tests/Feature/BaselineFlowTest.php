<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runDevguardForBaseline(array $args): array
{
    $bin = realpath(__DIR__ . '/../../bin/devguard');
    $process = new Process(array_merge([$bin], $args));
    $process->run();
    return [
        'stdout' => $process->getOutput() . $process->getErrorOutput(),
        'exit' => $process->getExitCode() ?? 1,
    ];
}

it('end-to-end: generate baseline, re-run, all issues suppressed', function () {
    $fixture = fixturePath('sample-laravel-app-bad');
    $baseline = $fixture . '/devguard-baseline.json';

    // Sanity: no leftover baseline from a prior test
    @unlink($baseline);

    // Step 1: generate the baseline
    $gen = runDevguardForBaseline(['baseline', '--path=' . $fixture]);
    expect($gen['exit'])->toBe(0);
    expect($gen['stdout'])->toContain('Baseline written');
    expect(file_exists($baseline))->toBeTrue();

    // Step 2: re-run env tool — should report all issues as suppressed
    $rerun = runDevguardForBaseline(['run', 'env', '--path=' . $fixture]);
    expect($rerun['exit'])->toBe(0); // suppressed → no failures
    expect($rerun['stdout'])->toContain('suppressed');

    // Step 3: --no-baseline must surface them again
    $noBaseline = runDevguardForBaseline(['run', 'env', '--path=' . $fixture, '--no-baseline']);
    expect($noBaseline['exit'])->toBe(1); // failures back

    // Cleanup so the fixture doesn't carry baseline state into other tests
    @unlink($baseline);
});

it('errors loudly on a malformed baseline file (not silently)', function () {
    $fixture = fixturePath('sample-laravel-app-good');
    $baseline = $fixture . '/devguard-baseline.json';

    // Bogus version → loader throws, RunCommand surfaces it
    file_put_contents($baseline, json_encode(['version' => 999, 'issues' => []]));

    try {
        $r = runDevguardForBaseline(['run', 'deploy', '--path=' . $fixture]);

        // RunCommand catches the exception and prints a friendly error
        // with a suggestion to regenerate. Filter is then bypassed.
        expect($r['stdout'])->toContain('Baseline file is invalid');
    } finally {
        @unlink($baseline);
    }
});
