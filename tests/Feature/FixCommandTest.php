<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Build a throwaway project on disk with a .env that's missing keys
 * present in .env.example. Returns the absolute path.
 */
function fixCommandFixture(string $envExample, string $env): string
{
    $root = sys_get_temp_dir() . '/devguard-fix-cmd-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env.example', $envExample);
    file_put_contents($root . '/.env', $env);
    return $root;
}

function runFix(array $args): array
{
    $bin = realpath(__DIR__ . '/../../bin/devguard');
    $process = new Process(array_merge([$bin], $args));
    $process->run();
    return [
        'stdout' => $process->getOutput() . $process->getErrorOutput(),
        'exit' => $process->getExitCode() ?? 1,
    ];
}

it('fix env --dry-run lists the plan without mutating the .env', function () {
    $root = fixCommandFixture(
        envExample: "APP_NAME=Laravel\nDB_CONNECTION=mysql\n",
        env: "APP_NAME=Laravel\n",
    );
    $envBefore = file_get_contents($root . '/.env');

    $r = runFix(['fix', 'env', '--dry-run', '--path=' . $root]);

    expect($r['exit'])->toBe(0);
    expect($r['stdout'])->toContain('DB_CONNECTION');
    expect($r['stdout'])->toContain('Dry run');

    // .env unchanged, no backup written.
    expect(file_get_contents($root . '/.env'))->toBe($envBefore);
    expect(file_exists($root . '/.env.devguard.bak'))->toBeFalse();
});

it('fix env --yes applies every missing key and writes a backup', function () {
    $root = fixCommandFixture(
        envExample: "APP_NAME=Laravel\nDB_CONNECTION=mysql\nMAIL_FROM=ops@example.com\n",
        env: "APP_NAME=Laravel\n",
    );

    $r = runFix(['fix', 'env', '--yes', '--path=' . $root]);

    expect($r['exit'])->toBe(0);
    expect($r['stdout'])->toContain('applied');

    $envAfter = file_get_contents($root . '/.env');
    expect($envAfter)->toContain('DB_CONNECTION=mysql');
    expect($envAfter)->toContain('MAIL_FROM=ops@example.com');

    // Backup matches the pre-fix state.
    expect(file_get_contents($root . '/.env.devguard.bak'))->toBe("APP_NAME=Laravel\n");
});

it('fix returns success and a friendly message when nothing needs fixing', function () {
    $root = fixCommandFixture(
        envExample: "APP_NAME=Laravel\n",
        env: "APP_NAME=Laravel\n",
    );

    $r = runFix(['fix', 'env', '--yes', '--path=' . $root]);

    expect($r['exit'])->toBe(0);
    expect($r['stdout'])->toContain('Nothing to fix');
});

it('fix architecture explains it cannot auto-fix and exits 0', function () {
    // Architecture tool is intentionally not FixableToolInterface — the
    // command must say so without crashing.
    $root = sys_get_temp_dir() . '/devguard-fix-arch-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');

    $r = runFix(['fix', 'architecture', '--path=' . $root]);

    expect($r['exit'])->toBe(0);
    expect($r['stdout'])->toContain('No fixable tools');
});
