<?php

declare(strict_types=1);

namespace DevGuard\Results;

final class ToolReport
{
    /** @var array<int, CheckResult|RuleResult> */
    private array $results = [];

    public function __construct(
        public readonly string $tool,
        public readonly string $title,
        public readonly ?int $score = null,
    ) {}

    public function add(CheckResult|RuleResult $result): void
    {
        $this->results[] = $result;
    }

    /** @return array<int, CheckResult|RuleResult> */
    public function results(): array
    {
        return $this->results;
    }

    public function hasFailures(): bool
    {
        foreach ($this->results as $r) {
            if ($r->status === Status::Fail) {
                return true;
            }
        }
        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->results as $r) {
            if ($r->status === Status::Warning) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, CheckResult|RuleResult> */
    public function suggestions(): array
    {
        return array_values(array_filter(
            $this->results,
            fn ($r) => $r->suggestion !== null && $r->status !== Status::Pass
        ));
    }

    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'title' => $this->title,
            'score' => $this->score,
            'passed' => ! $this->hasFailures(),
            'results' => array_map(fn ($r) => $r->toArray(), $this->results),
        ];
    }
}
