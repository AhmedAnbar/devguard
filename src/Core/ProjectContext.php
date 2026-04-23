<?php

declare(strict_types=1);

namespace DevGuard\Core;

use Dotenv\Dotenv;
use RuntimeException;

final class ProjectContext
{
    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $composerJson
     * @param array<string, true>|null $changedFiles  set of project-relative
     *        paths to scan, or null for "scan everything." Set-style map for
     *        O(1) lookups in shouldScan(). Populated by RunCommand when
     *        --changed-only is passed.
     */
    private function __construct(
        public readonly string $rootPath,
        public readonly bool $isLaravel,
        public readonly ?string $laravelVersion,
        public readonly array $env,
        public readonly array $composerJson,
        public readonly ?array $changedFiles = null,
    ) {}

    /**
     * @param array<int, string>|null $changedFiles  list of project-relative
     *        paths from `git diff`, or null to scan everything.
     */
    public static function detect(string $startPath, ?array $changedFiles = null): self
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
            changedFiles: $changedFiles !== null ? array_fill_keys($changedFiles, true) : null,
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

    /**
     * Should the given project-relative file be scanned? Returns true when
     * --changed-only was NOT passed (scan everything is the default), or
     * when the file is in the changed-files set.
     *
     * Per-file rules call this to honor --changed-only without each rule
     * having to know about git or input flags. Non-file rules (deploy
     * checks, deps audit) ignore this entirely — their concerns are
     * project-wide regardless of which files changed.
     */
    public function shouldScan(string $relativePath): bool
    {
        if ($this->changedFiles === null) {
            return true;
        }
        return isset($this->changedFiles[$relativePath]);
    }

    /**
     * True when --changed-only is active and at least one path was supplied.
     * Rules use this to emit a friendly "no changed files in scope" pass
     * instead of the usual "all clean" message — avoids confusion when
     * the empty result is due to filtering, not actual cleanliness.
     */
    public function isChangedOnly(): bool
    {
        return $this->changedFiles !== null;
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
