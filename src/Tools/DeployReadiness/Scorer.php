<?php

declare(strict_types=1);

namespace DevGuard\Tools\DeployReadiness;

use DevGuard\Results\CheckResult;
use DevGuard\Results\Status;

final class Scorer
{
    public function __construct(
        private readonly int $startingScore = 100,
        private readonly int $minScore = 0,
    ) {}

    /** @param array<int, CheckResult> $results */
    public function score(array $results): int
    {
        $score = $this->startingScore;

        foreach ($results as $result) {
            $score -= match ($result->status) {
                Status::Fail => $result->impact,
                Status::Warning => (int) floor($result->impact / 2),
                Status::Pass => 0,
            };
        }

        return max($this->minScore, $score);
    }
}
