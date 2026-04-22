<?php

declare(strict_types=1);

namespace DevGuard\Core\Baseline;

use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;

/**
 * An immutable snapshot of "issues we have already accepted as existing."
 *
 * The baseline is the answer to "this legacy app has 200 issues — how do
 * we adopt DevGuard without fixing them all today?". You record the
 * existing state, commit the baseline file, and only NEW issues surface.
 *
 * Signature scheme: SHA-1 of (rule_name + file + message). Deliberately
 * does NOT include line numbers — they shift on every edit and would
 * cause endless baseline churn. Trade-off: if the same exact issue
 * appears twice in a file, only one slot in the baseline; the second
 * triggers as new. Acceptable — duplicates are rare and arguably worth
 * seeing.
 */
final readonly class Baseline
{
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, true> $signatures map of signature → true
     *                                        (set semantics for O(1) lookup)
     * @param array<string, mixed> $meta      generated_at, tool_versions, etc.
     */
    public function __construct(
        public array $signatures,
        public array $meta = [],
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    public function hasSignature(string $signature): bool
    {
        return isset($this->signatures[$signature]);
    }

    public function size(): int
    {
        return count($this->signatures);
    }

    /**
     * Compute the stable signature for a result. Public so other callers
     * (BaselineLoader, ResultFilter) can hash without duplicating logic.
     */
    public static function signatureFor(CheckResult|RuleResult $result): string
    {
        $file = $result instanceof RuleResult ? ($result->file ?? '') : '';
        return sha1($result->name . '|' . $file . '|' . $result->message);
    }
}
