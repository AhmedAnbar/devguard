<?php

declare(strict_types=1);

use DevGuard\Tools\EnvAudit\Support\EnvFileDiscovery;

function discoveryFixture(array $envFiles): string
{
    $root = sys_get_temp_dir() . '/devguard-disco-' . uniqid('', true);
    mkdir($root);
    foreach ($envFiles as $name) {
        file_put_contents($root . '/' . $name, '');
    }
    return $root;
}

it('returns extra .env files but excludes .env and .env.example', function () {
    $root = discoveryFixture(['.env', '.env.example', '.env.testing', '.env.staging']);

    $found = (new EnvFileDiscovery())->discover($root);

    expect($found)->toBe(['.env.staging', '.env.testing']);
});

it('skips backup file patterns so we do not compare against generated copies', function () {
    $root = discoveryFixture([
        '.env.example',
        '.env.testing',
        '.env.devguard.bak',
        '.env.backup',
        '.env.previous',
        '.env.local.bak',
    ]);

    $found = (new EnvFileDiscovery())->discover($root);

    expect($found)->toBe(['.env.testing']);
});

it('returns an empty list when only .env / .env.example exist', function () {
    $root = discoveryFixture(['.env', '.env.example']);

    expect((new EnvFileDiscovery())->discover($root))->toBe([]);
});

it('skips directories that happen to match the .env* glob', function () {
    $root = discoveryFixture(['.env.example']);
    mkdir($root . '/.env.d'); // someone could create a settings dir named like an env file

    expect((new EnvFileDiscovery())->discover($root))->toBe([]);
});
