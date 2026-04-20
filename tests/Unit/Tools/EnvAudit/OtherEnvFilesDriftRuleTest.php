<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Status;
use DevGuard\Tools\EnvAudit\Rules\OtherEnvFilesDriftRule;

function driftProject(array $files): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-drift-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    foreach ($files as $name => $contents) {
        file_put_contents($root . '/' . $name, $contents);
    }
    return ProjectContext::detect($root);
}

it('warns about each key present in .env.example but missing from another env file', function () {
    $ctx = driftProject([
        '.env.example' => "APP_NAME=X\nDB_HOST=localhost\nMAIL_FROM=ops\n",
        '.env' => "APP_NAME=X\n",
        '.env.testing' => "APP_NAME=X\n", // missing DB_HOST + MAIL_FROM
    ]);

    $results = (new OtherEnvFilesDriftRule())->run($ctx);

    // 2 missing keys → 2 warnings
    expect($results)->toHaveCount(2);
    foreach ($results as $r) {
        expect($r->status)->toBe(Status::Warning);
        expect($r->file)->toBe('.env.testing');
    }

    $messages = array_map(fn ($r) => $r->message, $results);
    expect($messages)->toContain('Missing in .env.testing: DB_HOST');
    expect($messages)->toContain('Missing in .env.testing: MAIL_FROM');
});

it('passes a file that has every key from .env.example', function () {
    $ctx = driftProject([
        '.env.example' => "APP_NAME=X\nDB_HOST=localhost\n",
        '.env.staging' => "APP_NAME=stage\nDB_HOST=db.staging.local\n",
    ]);

    $results = (new OtherEnvFilesDriftRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
    expect($results[0]->message)->toContain('.env.staging: all keys');
});

it('reports nothing when there are no extra env files beyond .env / .env.example', function () {
    $ctx = driftProject([
        '.env.example' => "APP_NAME=X\n",
        '.env' => "APP_NAME=X\n",
    ]);

    $results = (new OtherEnvFilesDriftRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
    expect($results[0]->message)->toContain('No additional .env files');
});

it('skips silently when .env.example is missing (other rule already covers that)', function () {
    $ctx = driftProject([
        '.env.testing' => "APP_NAME=X\n",
    ]);

    $results = (new OtherEnvFilesDriftRule())->run($ctx);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(Status::Pass);
    expect($results[0]->message)->toContain('Skipped');
});
