<?php

declare(strict_types=1);

namespace DevGuard\Contracts;

/**
 * Marker contract for tools that own one or more FixableInterface rules.
 *
 * The FixCommand uses this to discover what's fixable inside a tool without
 * knowing the tool's internal structure (rules vs. checks vs. anything else).
 * Tools opt in by implementing this — non-fixable tools (architecture)
 * simply don't.
 */
interface FixableToolInterface extends ToolInterface
{
    /** @return array<int, FixableInterface> */
    public function fixableRules(): array;
}
