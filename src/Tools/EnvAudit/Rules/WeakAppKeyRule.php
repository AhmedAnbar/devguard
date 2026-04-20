<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;

final class WeakAppKeyRule implements RuleInterface
{
    /** Common foot-guns that look "set" but aren't real keys. */
    private const KNOWN_WEAK_VALUES = [
        'base64:',
        'somerandomstring',
        'changeme',
        'your-app-key-here',
    ];

    public function name(): string
    {
        return 'weak_app_key';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->isLaravel) {
            return [RuleResult::pass($this->name(), 'Skipped (not a Laravel project)')];
        }

        $key = $ctx->envValue('APP_KEY');

        if ($key === null || $key === '') {
            return [RuleResult::fail(
                $this->name(),
                'APP_KEY is not set in .env',
                '.env',
                null,
                'Run `php artisan key:generate` to set a secure application key.'
            )];
        }

        $keyStr = (string) $key;
        $lower = strtolower($keyStr);

        foreach (self::KNOWN_WEAK_VALUES as $weak) {
            if ($lower === strtolower($weak)) {
                return [RuleResult::fail(
                    $this->name(),
                    "APP_KEY is a known placeholder ({$keyStr})",
                    '.env',
                    null,
                    'Run `php artisan key:generate` to replace the placeholder with a secure key.'
                )];
            }
        }

        // Laravel keys are typically "base64:<44 base64 chars>" — total length 51+
        if (str_starts_with($lower, 'base64:') && strlen($keyStr) < 50) {
            return [RuleResult::fail(
                $this->name(),
                "APP_KEY looks truncated or invalid (length {$this->byteLen($keyStr)})",
                '.env',
                null,
                'Run `php artisan key:generate` to generate a full 32-byte base64 key.'
            )];
        }

        return [RuleResult::pass($this->name(), 'APP_KEY is set and non-trivial')];
    }

    private function byteLen(string $s): int
    {
        return strlen($s);
    }
}
