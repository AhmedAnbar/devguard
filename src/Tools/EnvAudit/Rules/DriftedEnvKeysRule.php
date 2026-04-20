<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\EnvAudit\Support\EnvFileLoader;

final class DriftedEnvKeysRule implements RuleInterface
{
    public function __construct(private readonly EnvFileLoader $loader = new EnvFileLoader()) {}

    public function name(): string
    {
        return 'drifted_env_keys';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->fileExists('.env') || ! $ctx->fileExists('.env.example')) {
            return [RuleResult::pass($this->name(), 'Skipped (one of .env / .env.example is missing)')];
        }

        $exampleKeys = array_keys($this->loader->load($ctx->rootPath, '.env.example'));
        $envKeys = array_keys($this->loader->load($ctx->rootPath, '.env'));

        $drift = array_diff($envKeys, $exampleKeys);

        if ($drift === []) {
            return [RuleResult::pass($this->name(), 'No drift between .env and .env.example')];
        }

        $results = [];
        foreach ($drift as $key) {
            $results[] = RuleResult::warn(
                $this->name(),
                "In .env but not in .env.example: {$key}",
                '.env.example',
                null,
                "Add {$key}= to .env.example (with a placeholder value) so collaborators know it's expected."
            );
        }
        return $results;
    }
}
