<?php

declare(strict_types=1);

namespace DevGuard\Core\Baseline;

use DevGuard\Results\CheckResult;
use DevGuard\Results\RuleResult;
use DevGuard\Results\Status;
use DevGuard\Results\ToolReport;

/**
 * Drops results that the user has already accepted, either via the baseline
 * file (whole-project record of "we know about these") or inline
 * `@devguard-ignore` annotations (per-issue local override).
 *
 * Wired in at the RunCommand layer so every existing and future tool
 * gets baseline + annotation support for free — same Strategy reasoning
 * as the renderer pipeline.
 *
 * Pass results are never suppressed: there's nothing to silence and
 * counting them as "suppressed" would inflate the count meaninglessly.
 */
final class ResultFilter
{
    public function __construct(
        private readonly Baseline $baseline,
        private readonly IgnoreAnnotationParser $annotations,
        private readonly string $projectRoot,
    ) {}

    /**
     * Filter the report in-place, set its suppressedCount, return how many
     * were suppressed (also available via $report->suppressedCount()).
     */
    public function apply(ToolReport $report): int
    {
        $kept = [];
        $suppressed = 0;

        foreach ($report->results() as $result) {
            if ($this->shouldSuppress($result)) {
                $suppressed++;
                continue;
            }
            $kept[] = $result;
        }

        $report->replaceResults($kept);
        $report->setSuppressedCount($suppressed);
        return $suppressed;
    }

    private function shouldSuppress(CheckResult|RuleResult $result): bool
    {
        // Pass results aren't issues — never suppress.
        if ($result->status === Status::Pass) {
            return false;
        }

        // 1) Baseline match: "we already accepted this exact issue."
        if ($this->baseline->hasSignature(Baseline::signatureFor($result))) {
            return true;
        }

        // 2) Inline annotation: only meaningful for results with a real
        //    file:line — checks (deploy-style) live in env files /
        //    config we don't annotate.
        if ($result instanceof RuleResult && $result->file !== null && $result->line !== null) {
            $absolute = $this->absolutePath($result->file);
            if ($this->annotations->isSuppressed($absolute, $result->line, $result->name)) {
                return true;
            }
        }

        return false;
    }

    private function absolutePath(string $relative): string
    {
        if (str_starts_with($relative, '/')) {
            return $relative;
        }
        return rtrim($this->projectRoot, '/') . '/' . ltrim($relative, '/');
    }
}
