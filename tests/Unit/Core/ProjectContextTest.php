<?php

declare(strict_types=1);

use DevGuard\Core\ProjectContext;

it('walks up to find composer.json', function () {
    $deepPath = fixturePath('sample-laravel-app-good') . '/app/Http/Controllers';
    $ctx = ProjectContext::detect($deepPath);

    expect($ctx->rootPath)->toBe(fixturePath('sample-laravel-app-good'));
});

it('detects Laravel from composer.json require', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));

    expect($ctx->isLaravel)->toBeTrue();
    expect($ctx->laravelVersion)->toBe('^11.0');
});

it('throws when no composer.json found anywhere up the tree', function () {
    ProjectContext::detect('/');
})->throws(RuntimeException::class, 'No composer.json found');

it('parses .env into the env array', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));

    expect($ctx->envValue('APP_DEBUG'))->toBe('false');
    expect($ctx->envValue('CACHE_STORE'))->toBe('redis');
});

it('returns the default for missing env keys', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));

    expect($ctx->envValue('NOT_SET', 'fallback'))->toBe('fallback');
});

it('resolves relative paths against the project root', function () {
    $ctx = ProjectContext::detect(fixturePath('sample-laravel-app-good'));

    expect($ctx->path('routes/api.php'))
        ->toBe(fixturePath('sample-laravel-app-good') . '/routes/api.php');
    expect($ctx->fileExists('routes/api.php'))->toBeTrue();
    expect($ctx->fileExists('does/not/exist'))->toBeFalse();
});
