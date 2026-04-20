<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Support;

/**
 * Discovers .env-family files in a project root, minus the ones we
 * already handle elsewhere or that are obviously backups.
 *
 * `.env` and `.env.example` are owned by other rules — we surface only
 * the *additional* environment files (`.env.testing`, `.env.staging`, etc.)
 * so the OtherEnvFilesDriftRule doesn't double-report what's already
 * covered by MissingEnvKeysRule + DriftedEnvKeysRule.
 */
final class EnvFileDiscovery
{
    /** Files that are handled by other rules and must not appear here. */
    private const OWNED_ELSEWHERE = ['.env', '.env.example'];

    /** Backup / generated files we never want to compare against templates. */
    private const SKIP_NAMES = [
        '.env.devguard.bak', // our own backup, written by MissingEnvKeysRule fix
        '.env.backup',
        '.env.previous',
    ];

    /**
     * @return array<int, string> filenames (not paths) of additional .env files,
     *                            sorted alphabetically for stable output.
     */
    public function discover(string $rootPath): array
    {
        $matches = glob(rtrim($rootPath, '/') . '/.env*');
        if ($matches === false) {
            return [];
        }

        $found = [];
        foreach ($matches as $absolute) {
            $name = basename($absolute);

            if (in_array($name, self::OWNED_ELSEWHERE, true)) {
                continue;
            }
            if (in_array($name, self::SKIP_NAMES, true)) {
                continue;
            }
            // Catch-all for "*.bak" — covers .env.local.bak, .env.foo.bak, etc.
            if (str_ends_with($name, '.bak')) {
                continue;
            }
            // Skip directories (someone could `mkdir .env.d`).
            if (! is_file($absolute)) {
                continue;
            }

            $found[] = $name;
        }

        sort($found);
        return $found;
    }
}
