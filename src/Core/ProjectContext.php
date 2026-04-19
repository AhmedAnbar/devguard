<?php

declare(strict_types=1);

namespace DevGuard\Core;

use Dotenv\Dotenv;
use RuntimeException;

final class ProjectContext
{
    /** @param array<string, mixed> $env */
    /** @param array<string, mixed> $composerJson */
    private function __construct(
        public readonly string $rootPath,
        public readonly bool $isLaravel,
        public readonly ?string $laravelVersion,
        public readonly array $env,
        public readonly array $composerJson,
    ) {}

    public static function detect(string $startPath): self
    {
        $root = self::findProjectRoot($startPath);

        if ($root === null) {
            throw new RuntimeException(
                "No composer.json found in '{$startPath}' or any parent directory."
            );
        }

        $composer = self::readComposerJson($root);
        $laravelVersion = $composer['require']['laravel/framework'] ?? null;

        return new self(
            rootPath: $root,
            isLaravel: $laravelVersion !== null,
            laravelVersion: $laravelVersion,
            env: self::loadEnv($root),
            composerJson: $composer,
        );
    }

    public function path(string $relative = ''): string
    {
        return $relative === ''
            ? $this->rootPath
            : rtrim($this->rootPath, '/') . '/' . ltrim($relative, '/');
    }

    public function fileExists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function envValue(string $key, mixed $default = null): mixed
    {
        return $this->env[$key] ?? $default;
    }

    private static function findProjectRoot(string $start): ?string
    {
        $current = realpath($start);

        if ($current === false) {
            return null;
        }

        while ($current !== '/' && $current !== '') {
            if (file_exists($current . '/composer.json')) {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function readComposerJson(string $root): array
    {
        $contents = file_get_contents($root . '/composer.json');
        if ($contents === false) {
            throw new RuntimeException("Cannot read composer.json at {$root}");
        }
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid composer.json at {$root}");
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function loadEnv(string $root): array
    {
        if (! file_exists($root . '/.env')) {
            return [];
        }

        try {
            $dotenv = Dotenv::createArrayBacked($root);
            return $dotenv->safeLoad();
        } catch (\Throwable) {
            return [];
        }
    }
}
