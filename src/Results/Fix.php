<?php

declare(strict_types=1);

namespace DevGuard\Results;

/**
 * A single proposed mutation to the user's project.
 *
 * Rules build these in proposeFixes() — the FixCommand prints them, prompts
 * the user, and hands each one back to applyFix() if accepted. The payload
 * is rule-private metadata (advisory ID, env key, etc.) and the framework
 * treats it as opaque.
 */
final readonly class Fix
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $ruleName,
        public string $target,
        public string $description,
        public array $payload = [],
    ) {}
}
