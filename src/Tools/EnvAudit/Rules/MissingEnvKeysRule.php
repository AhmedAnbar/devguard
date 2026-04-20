<?php

declare(strict_types=1);

namespace DevGuard\Tools\EnvAudit\Rules;

use DevGuard\Contracts\RuleInterface;
use DevGuard\Core\ProjectContext;
use DevGuard\Results\RuleResult;
use DevGuard\Tools\EnvAudit\Support\EnvFileLoader;

final class MissingEnvKeysRule implements RuleInterface
{
    public function __construct(private readonly EnvFileLoader $loader = new EnvFileLoader()) {}

    public function name(): string
    {
        return 'missing_env_keys';
    }

    /** @return array<int, RuleResult> */
    public function run(ProjectContext $ctx): array
    {
        if (! $ctx->fileExists('.env.example')) {
            return [RuleResult::pass($this->name(), 'Skipped (.env.example missing — see env_example_exists rule)')];
        }

        if (! $ctx->fileExists('.env')) {
            return [RuleResult::fail(
                $this->name(),
                '.env file is missing — every key in .env.example is effectively missing',
                '.env',
                null,
                'Copy .env.example to .env and fill in the values for your environment.'
            )];
        }

        $exampleKeys = array_keys($this->loader->load($ctx->rootPath, '.env.example'));
        $envKeys = array_keys($this->loader->load($ctx->rootPath, '.env'));

        $missing = array_diff($exampleKeys, $envKeys);

        if ($missing === []) {
            return [RuleResult::pass($this->name(), 'All keys from .env.example are present in .env')];
        }

        $results = [];
        foreach ($missing as $key) {
            $results[] = RuleResult::fail(
                $this->name(),
                "Missing in .env: {$key}",
                '.env',
                null,
                "Add {$key}= to your .env (use the value from .env.example as a starting point)."
            );
        }
        return $results;
    }
}
