<?php

declare(strict_types=1);

namespace DevGuard\Core\Config;

use RuntimeException;

final class ConfigLoader
{
    public function __construct(
        private readonly string $defaultConfigPath,
    ) {}

    public function load(string $projectRoot): Config
    {
        $defaults = $this->requireArray($this->defaultConfigPath);

        $userConfigPath = $projectRoot . '/devguard.php';
        if (file_exists($userConfigPath)) {
            $user = $this->requireArray($userConfigPath);
            return new Config(array_replace_recursive($defaults, $user));
        }

        return new Config($defaults);
    }

    /** @return array<string, mixed> */
    private function requireArray(string $path): array
    {
        if (! file_exists($path)) {
            throw new RuntimeException("Config file not found: {$path}");
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new RuntimeException("Config file must return an array: {$path}");
        }

        return $data;
    }
}
