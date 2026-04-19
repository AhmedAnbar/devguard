<?php

declare(strict_types=1);

use DevGuard\Core\Config\Config;
use DevGuard\Core\Config\ConfigLoader;

it('reads dot-notation paths', function () {
    $config = new Config([
        'tools' => [
            'deploy' => [
                'enabled' => true,
                'checks' => ['debug_mode' => ['impact' => 15]],
            ],
        ],
    ]);

    expect($config->get('tools.deploy.enabled'))->toBeTrue();
    expect($config->get('tools.deploy.checks.debug_mode.impact'))->toBe(15);
});

it('returns the default for missing paths', function () {
    $config = new Config(['a' => ['b' => 1]]);

    expect($config->get('a.b.c.d', 'fallback'))->toBe('fallback');
    expect($config->get('missing', 42))->toBe(42);
});

it('merges user overrides on top of defaults', function () {
    $tmp = sys_get_temp_dir() . '/devguard-config-' . uniqid();
    mkdir($tmp);
    file_put_contents($tmp . '/composer.json', '{}');
    file_put_contents($tmp . '/devguard.php', '<?php return ["tools" => ["deploy" => ["checks" => ["debug_mode" => ["impact" => 99]]]]];');

    $loader = new ConfigLoader(__DIR__ . '/../../../../config/devguard.php');
    $config = $loader->load($tmp);

    expect($config->get('tools.deploy.checks.debug_mode.impact'))->toBe(99);
    expect($config->get('tools.deploy.checks.env_file_exists.impact'))->toBe(20);

    unlink($tmp . '/devguard.php');
    unlink($tmp . '/composer.json');
    rmdir($tmp);
});
