<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\EnvAudit\Rules\UndeclaredEnvCallsRule;

function envCallsProject(array $envFiles, array $phpFiles): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-envcalls-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    foreach ($envFiles as $name => $contents) {
        file_put_contents($root . '/' . $name, $contents);
    }
    foreach ($phpFiles as $relPath => $contents) {
        $abs = $root . '/' . $relPath;
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($abs, $contents);
    }
    return ProjectContext::detect($root);
}

it('flags env() calls referencing keys not declared in any .env file', function () {
    $ctx = envCallsProject(
        envFiles: [
            '.env.example' => "APP_NAME=X\n",
        ],
        phpFiles: [
            'config/services.php' => '<?php return ["a" => env("APP_NAME"), "b" => env("UNKNOWN_KEY")];',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Fail);
    expect($results[0]->message)->toContain('UNKNOWN_KEY');
    expect($results[0]->file)->toBe('config/services.php');
});

it('treats keys declared in any .env-family file (union) as valid', function () {
    // FOO is only in .env.testing — the rule should still treat it as declared
    // because it's reachable at runtime when APP_ENV=testing.
    $ctx = envCallsProject(
        envFiles: [
            '.env.example' => "APP_NAME=X\n",
            '.env.testing' => "APP_NAME=X\nFOO=bar\n",
        ],
        phpFiles: [
            'config/services.php' => '<?php return ["foo" => env("FOO")];',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
});

it('skips dynamic env() calls (cannot statically validate them)', function () {
    $ctx = envCallsProject(
        envFiles: ['.env.example' => "APP_NAME=X\n"],
        phpFiles: [
            'config/services.php' => '<?php return ["x" => env($key), "y" => env("APP_NAME" . $suffix)];',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
});

it('handles Env::get() facade calls the same as env()', function () {
    $ctx = envCallsProject(
        envFiles: ['.env.example' => "APP_NAME=X\n"],
        phpFiles: [
            'config/services.php' => '<?php use Illuminate\Support\Env; return ["k" => Env::get("MISSING_VIA_FACADE")];',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Fail);
    expect($results[0]->message)->toContain('MISSING_VIA_FACADE');
    expect($results[0]->message)->toContain('Env::get');
});

it('dedupes the same undeclared key when called multiple times in one file', function () {
    $ctx = envCallsProject(
        envFiles: ['.env.example' => "APP_NAME=X\n"],
        phpFiles: [
            'config/services.php' => '<?php return ["a" => env("MISSING"), "b" => env("MISSING"), "c" => env("MISSING")];',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Fail);
});

it('reports the same key separately when it appears in different files', function () {
    $ctx = envCallsProject(
        envFiles: ['.env.example' => "APP_NAME=X\n"],
        phpFiles: [
            'config/a.php' => '<?php return env("SAME_MISSING");',
            'config/b.php' => '<?php return env("SAME_MISSING");',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(2);
    $files = array_map(fn ($r) => $r->file, $results);
    sort($files);
    expect($files)->toBe(['config/a.php', 'config/b.php']);
});

it('only scans Laravel runtime dirs (skips tests/, vendor/, storage/)', function () {
    $ctx = envCallsProject(
        envFiles: ['.env.example' => "APP_NAME=X\n"],
        phpFiles: [
            // NOT in a scanned dir — must NOT be reported even though it
            // references an undeclared key.
            'tests/SomeTest.php' => '<?php return env("UNDECLARED_BUT_IN_TESTS");',
            'vendor/foo/bar.php' => '<?php return env("UNDECLARED_BUT_IN_VENDOR");',
        ],
    );

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
});
