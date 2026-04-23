<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;

function ctxWithChangedFiles(?array $changedFiles): ProjectContext
{
    $root = sys_get_temp_dir() . '/devguard-ctx-' . uniqid('', true);
    mkdir($root);
    file_put_contents($root . '/composer.json', '{}');
    return ProjectContext::detect($root, $changedFiles);
}

it('shouldScan returns true for everything when changedFiles is null', function () {
    $ctx = ctxWithChangedFiles(null);

    expect($ctx->shouldScan('app/Http/Controllers/UserController.php'))->toBeTrue();
    expect($ctx->shouldScan('config/anything.php'))->toBeTrue();
    expect($ctx->isChangedOnly())->toBeFalse();
});

it('shouldScan returns true ONLY for paths in the changedFiles set', function () {
    $ctx = ctxWithChangedFiles([
        'app/Http/Controllers/UserController.php',
        'config/auth.php',
    ]);

    expect($ctx->shouldScan('app/Http/Controllers/UserController.php'))->toBeTrue();
    expect($ctx->shouldScan('config/auth.php'))->toBeTrue();
    // Not in the set → false (path not scanned by --changed-only).
    expect($ctx->shouldScan('app/Http/Controllers/OtherController.php'))->toBeFalse();
    expect($ctx->shouldScan('config/services.php'))->toBeFalse();
    expect($ctx->isChangedOnly())->toBeTrue();
});

it('empty changedFiles list (no files changed) suppresses scanning of every file', function () {
    // This is the pre-commit-with-no-staged-files case. Better to scan
    // nothing than to scan everything by mistake.
    $ctx = ctxWithChangedFiles([]);

    expect($ctx->shouldScan('anything.php'))->toBeFalse();
    expect($ctx->isChangedOnly())->toBeTrue();
});
