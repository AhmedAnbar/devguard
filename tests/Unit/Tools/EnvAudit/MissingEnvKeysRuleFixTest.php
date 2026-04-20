<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\EnvAudit\Rules\MissingEnvKeysRule;

function envFixProject(string $envExample, string $env): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-env-fix-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env.example', $envExample);
    file_put_contents($root . '/.env', $env);
    return ProjectContext::detect($root);
}

it('proposes one fix per missing env key, with values from .env.example', function () {
    $ctx = envFixProject(
        envExample: "APP_NAME=Laravel\nDB_CONNECTION=mysql\nMAIL_FROM=ops@example.com\n",
        env: "APP_NAME=Laravel\n",
    );

    $fixes = (new MissingEnvKeysRule())->proposeFixes($ctx);

    expect($fixes)->toHaveCount(2);

    $targets = array_map(fn ($f) => $f->target, $fixes);
    expect($targets)->toContain('DB_CONNECTION');
    expect($targets)->toContain('MAIL_FROM');

    $byKey = [];
    foreach ($fixes as $f) {
        $byKey[$f->target] = $f;
    }
    expect($byKey['DB_CONNECTION']->payload['value'])->toBe('mysql');
    expect($byKey['MAIL_FROM']->payload['value'])->toBe('ops@example.com');
});

it('applies a fix by appending the key=value line and creating a backup', function () {
    $ctx = envFixProject(
        envExample: "APP_NAME=Laravel\nDB_CONNECTION=mysql\n",
        env: "APP_NAME=Laravel\n",
    );

    $rule = new MissingEnvKeysRule();
    $fixes = $rule->proposeFixes($ctx);
    $result = $rule->applyFix($ctx, $fixes[0]);

    expect($result->status)->toBe(Status::Pass);
    expect($result->message)->toContain('DB_CONNECTION');

    // .env now contains the appended key on its own line.
    $env = file_get_contents($ctx->path('.env'));
    expect($env)->toContain("DB_CONNECTION=mysql");

    // Backup created with the pre-fix contents.
    $backup = file_get_contents($ctx->path('.env.devguard.bak'));
    expect($backup)->toBe("APP_NAME=Laravel\n");
});

it('only writes the backup once across multiple applyFix calls', function () {
    $ctx = envFixProject(
        envExample: "A=1\nB=2\nC=3\n",
        env: "A=1\n",
    );

    $rule = new MissingEnvKeysRule();
    $fixes = $rule->proposeFixes($ctx);
    foreach ($fixes as $fix) {
        $rule->applyFix($ctx, $fix);
    }

    // Backup must reflect the original .env, not any later mutation.
    $backup = file_get_contents($ctx->path('.env.devguard.bak'));
    expect($backup)->toBe("A=1\n");

    // Final .env has all three keys.
    $env = file_get_contents($ctx->path('.env'));
    expect($env)->toContain('B=2');
    expect($env)->toContain('C=3');
});

it('quotes values that contain whitespace when appending to .env', function () {
    // vlucas/phpdotenv strips '#'-comments at parse time, so we can't test
    // those round-trip — the rule only ever sees the post-parse value.
    // What we *can* prove: a value with a space round-trips correctly because
    // formatLine() re-wraps it in double quotes.
    $ctx = envFixProject(
        envExample: "APP_NAME=\"My App\"\n",
        env: '',
    );

    $rule = new MissingEnvKeysRule();
    foreach ($rule->proposeFixes($ctx) as $fix) {
        $rule->applyFix($ctx, $fix);
    }

    $env = file_get_contents($ctx->path('.env'));
    expect($env)->toContain('APP_NAME="My App"');
});

it('skips a fix when the key was added between propose and apply', function () {
    $ctx = envFixProject(
        envExample: "APP_NAME=Laravel\nDB_CONNECTION=mysql\n",
        env: "APP_NAME=Laravel\n",
    );

    $rule = new MissingEnvKeysRule();
    $fixes = $rule->proposeFixes($ctx);

    // Simulate the user adding the key by hand between propose and apply.
    file_put_contents($ctx->path('.env'), "DB_CONNECTION=pgsql\n", FILE_APPEND);

    $result = $rule->applyFix($ctx, $fixes[0]);
    expect($result->status)->toBe(Status::Warning);
    expect($result->message)->toContain('already present');

    // We did NOT clobber the user's pgsql value with the example's mysql.
    $env = file_get_contents($ctx->path('.env'));
    expect($env)->toContain('DB_CONNECTION=pgsql');
    expect($env)->not->toContain('DB_CONNECTION=mysql');
});

it('returns no fixes when .env is missing entirely', function () {
    // We deliberately don't auto-materialize a fresh .env from .env.example —
    // missing .env is a "you haven't set up yet" signal worth preserving.
    $root = sys_get_temp_dir() . '/devguard-no-env-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/.env.example', "APP_NAME=Laravel\n");
    $ctx = ProjectContext::detect($root);

    $fixes = (new MissingEnvKeysRule())->proposeFixes($ctx);
    expect($fixes)->toBe([]);
});
