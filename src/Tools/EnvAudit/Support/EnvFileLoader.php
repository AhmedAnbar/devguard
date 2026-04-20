<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Support;

use Dotenv\Dotenv;

final class EnvFileLoader
{
    /** @return array<string, mixed> */
    public function load(string $rootPath, string $filename): array
    {
        if (! file_exists($rootPath . '/' . $filename)) {
            return [];
        }

        try {
            return Dotenv::createArrayBacked($rootPath, $filename)->safeLoad();
        } catch (\Throwable) {
            return [];
        }
    }
}
