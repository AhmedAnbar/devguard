<?php

declare(strict_types=1);

namespace DevGuard\Contracts;

use DevGuard\Core\ProjectContext;
use DevGuard\Results\Fix;
use DevGuard\Results\FixResult;

/**
 * A rule that can mutate the project to resolve issues it surfaced.
 *
 * Two-phase contract: proposeFixes() is read-only and returns the plan;
 * applyFix() is the mutating step, called once per Fix the user accepts.
 *
 * The split lets the FixCommand show a full preview ('--dry-run') and
 * prompt-per-fix without entangling the rule with UI concerns.
 */
interface FixableInterface
{
    /**
     * Identifier used in fix-plan output and logs (typically matches the
     * containing rule's name(), but kept independent so non-rule fixers
     * can implement this contract too).
     */
    public function name(): string;

    /** @return array<int, Fix> */
    public function proposeFixes(ProjectContext $ctx): array;

    public function applyFix(ProjectContext $ctx, Fix $fix): FixResult;
}
