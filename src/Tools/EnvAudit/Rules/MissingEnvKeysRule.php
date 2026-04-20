<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\FixableInterface;
use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\Fix;
use DevGuard\Results\FixResult;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\EnvAudit\Support\EnvFileLoader;

final class MissingEnvKeysRule implements RuleInterface, FixableInterface
{
    public function __construct(private readonly EnvFileLoader $loader = new EnvFileLoader()) {}

    public function name(): string
    {
        return 'missing_env_keys';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->fileExists('.env.example')) {
            return [RuleResult::pass($this->name(), 'Skipped (.env.example missing — see env_example_exists rule)')];
        }

        if (! $ctx->fileExists('.env')) {
            return [RuleResult::fail(
                $this->name(),
                '.env file is missing — every key in .env.example is effectively missing',
                '.env',
                null,
                'Copy .env.example to .env and fill in the values for your environment.'
            )];
        }

        $exampleKeys = array_keys($this->loader->load($ctx->rootPath, '.env.example'));
        $envKeys = array_keys($this->loader->load($ctx->rootPath, '.env'));

        $missing = array_diff($exampleKeys, $envKeys);

        if ($missing === []) {
            return [RuleResult::pass($this->name(), 'All keys from .env.example are present in .env')];
        }

        $results = [];
        foreach ($missing as $key) {
            $results[] = RuleResult::fail(
                $this->name(),
                "Missing in .env: {$key}",
                '.env',
                null,
                "Add {$key}= to your .env (use the value from .env.example as a starting point)."
            );
        }
        return $results;
    }

    /**
     * One Fix per missing key. Apply appends "KEY=value" pulled from
     * .env.example, so users get the placeholder/default they wrote there
     * instead of an empty value they'd have to fill in twice.
     *
     * @return array<int, Fix>
     */
    public function proposeFixes(ProjectContext $ctx): array
    {
        // We need both files to know what to copy. .env missing entirely is a
        // bigger problem the user should fix manually (we don't want to
        // silently materialize a fresh .env from .env.example — that loses
        // the "I haven't set this up yet" signal).
        if (! $ctx->fileExists('.env.example') || ! $ctx->fileExists('.env')) {
            return [];
        }

        $example = $this->loader->load($ctx->rootPath, '.env.example');
        $env = $this->loader->load($ctx->rootPath, '.env');
        $missing = array_diff(array_keys($example), array_keys($env));

        $fixes = [];
        foreach ($missing as $key) {
            $value = (string) ($example[$key] ?? '');
            $fixes[] = new Fix(
                ruleName: $this->name(),
                target: $key,
                description: $value === ''
                    ? "Append `{$key}=` to .env"
                    : "Append `{$key}={$value}` to .env (value copied from .env.example)",
                payload: ['key' => $key, 'value' => $value],
            );
        }
        return $fixes;
    }

    public function applyFix(ProjectContext $ctx, Fix $fix): FixResult
    {
        $key = (string) ($fix->payload['key'] ?? '');
        $value = (string) ($fix->payload['value'] ?? '');

        if ($key === '') {
            return FixResult::failed($fix, 'Fix payload missing key name');
        }

        $envPath = $ctx->path('.env');
        if (! file_exists($envPath)) {
            return FixResult::failed($fix, '.env file disappeared since fix was proposed');
        }

        // Backup once per session so the user can recover. We only create the
        // backup if it doesn't already exist — successive applyFix calls in
        // the same run shouldn't overwrite the original .env we started from.
        $backupPath = $envPath . '.devguard.bak';
        if (! file_exists($backupPath)) {
            if (! @copy($envPath, $backupPath)) {
                return FixResult::failed($fix, "Could not write backup to {$backupPath}");
            }
        }

        $current = (string) file_get_contents($envPath);

        // Bail if the key already exists — proposeFixes was based on stale
        // state, or an earlier fix in this batch added it.
        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $current) === 1) {
            return FixResult::skipped($fix, "{$key} is already present in .env");
        }

        // Make sure we're appending on a new line (not concatenating onto
        // whatever the last line happens to be).
        $separator = ($current === '' || str_ends_with($current, "\n")) ? '' : "\n";
        $line = $separator . $this->formatLine($key, $value) . "\n";

        if (file_put_contents($envPath, $line, FILE_APPEND) === false) {
            return FixResult::failed($fix, "Could not append to {$envPath}");
        }

        return FixResult::applied($fix, "Added {$key} to .env");
    }

    /**
     * Quote values that contain spaces, '#', or quotes — otherwise dotenv
     * parsers will treat them as comments or split on whitespace.
     */
    private function formatLine(string $key, string $value): string
    {
        if ($value === '') {
            return "{$key}=";
        }

        $needsQuotes = (bool) preg_match('/[\s#"\']/', $value);
        if (! $needsQuotes) {
            return "{$key}={$value}";
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return "{$key}=\"{$escaped}\"";
    }
}
