<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;

final class EnvExampleExistsRule implements RuleInterface
{
    public function name(): string
    {
        return 'env_example_exists';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if ($ctx->fileExists('.env.example')) {
            return [RuleResult::pass($this->name(), '.env.example exists')];
        }

        return [RuleResult::fail(
            $this->name(),
            '.env.example is missing at project root',
            '.env.example',
            null,
            'Create a .env.example committed to git with all required keys (and safe placeholder values).'
        )];
    }
}
