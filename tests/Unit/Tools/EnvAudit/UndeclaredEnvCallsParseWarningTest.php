<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\EnvAudit\Rules\UndeclaredEnvCallsRule;

function brokenFileProject(string $brokenContents): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-broken-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env.example', "APP_NAME=Test\n");
    file_put_contents($root . '/.env', "APP_NAME=Test\n");
    mkdir($root . '/app/Http/Controllers', 0o755, true);
    file_put_contents($root . '/app/Http/Controllers/Broken.php', $brokenContents);
    return ProjectContext::detect($root);
}

it('warns (does NOT silently skip) when an env() call lives in an unparseable file', function () {
    // The exact failure mode a real user hit in v0.7.0:
    // an env() call at class body level → file does not parse →
    // pre-fix the rule said "all clean" and the new key was invisible.
    $broken = "<?php\nnamespace App\\Http\\Controllers;\nclass Broken {\n    env('FAKE_KEY');\n}\n";
    $ctx = brokenFileProject($broken);

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    $warnings = array_values(array_filter(
        $results,
        fn ($r) => $r->status === Status::Warning && str_contains($r->message, 'Could not parse')
    ));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]->message)->toContain('app/Http/Controllers/Broken.php');
    expect($warnings[0]->message)->toContain('syntax error');
    expect($warnings[0]->suggestion)->toContain('php -l');
});

it('still scans valid sibling files when one file in the run is unparseable', function () {
    $root = sys_get_temp_dir() . '/devguard-broken-mix-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env.example', "APP_NAME=Test\n");
    file_put_contents($root . '/.env', "APP_NAME=Test\n");
    mkdir($root . '/config', 0o755, true);
    // Valid file: should still be scanned and produce a fail.
    file_put_contents($root . '/config/services.php', '<?php return ["x" => env("UNDECLARED_BUT_VALID_FILE")];');
    // Broken file: should produce a warning, not crash the rule.
    file_put_contents($root . '/config/broken.php', '<?php class B { env("FAKE"); }');
    $ctx = ProjectContext::detect($root);

    $results = (new UndeclaredEnvCallsRule())->run($ctx);

    $hasFailFromValidFile = false;
    $hasWarnFromBrokenFile = false;
    foreach ($results as $r) {
        if ($r->status === Status::Fail && str_contains($r->message, 'UNDECLARED_BUT_VALID_FILE')) {
            $hasFailFromValidFile = true;
        }
        if ($r->status === Status::Warning && str_contains($r->message, 'broken.php')) {
            $hasWarnFromBrokenFile = true;
        }
    }

    expect($hasFailFromValidFile)->toBeTrue('valid sibling file must still be scanned');
    expect($hasWarnFromBrokenFile)->toBeTrue('broken file must produce a warning');
});
